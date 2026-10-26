<?php

namespace App\Services;

use App\Models\EmbeddingCache;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * RAG step 3: Embedding.
 *
 * An embedding is a numerical representation of text meaning: a list of a
 * few thousand floating point numbers (a "vector"). Text with similar
 * meaning ends up with vectors that are close together in that vector
 * space. This is what lets us find "relevant" chunks later by comparing
 * numbers instead of matching keywords.
 *
 * This service calls Google's Gemini Embeddings API to turn a piece of text
 * into its vector. It's used both for document chunks (once, when the
 * document is processed) and for the user's question (once per question,
 * at search time).
 */
class EmbeddingService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:batchEmbedContents';

    private const MAX_BATCH_SIZE = 100;

    private const MAX_RATE_LIMIT_RETRIES = 5;

    private const RATE_LIMIT_WAIT_SECONDS = 65;

    /**
     * The free tier also limits embedding *tokens* per minute (30,000). A
     * batch of 100 dense 1,000-character chunks (e.g. the World Bank report's
     * tables and figures) is already about that much, so batches are also
     * capped by estimated tokens, and sending pauses before the last minute's
     * total would go over. Kept below the real limit because the estimate
     * is rough.
     */
    private const TOKENS_PER_MINUTE = 24000;

    /** Rough, deliberately high estimate (real text averages ~4 chars/token). */
    private const CHARS_PER_TOKEN = 3;

    /** @var array<int, array{at: float, tokens: int}> Batches sent in the last minute. */
    private array $sent = [];

    /**
     * Convert a single piece of text into its embedding vector.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    /**
     * Convert multiple pieces of text into embedding vectors.
     * Batching is both faster and cheaper than calling the API per chunk.
     * Gemini accepts at most 100 texts per batch call, so larger documents
     * are sent in several batches.
     *
     * Every embedding is cached by text, so re-processing a document (or
     * asking the same question again) never spends API quota twice. Each
     * batch is cached as soon as it succeeds, so if the daily quota runs out
     * halfway through a large document, the next attempt resumes from there.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embedBatch(array $texts): array
    {
        $model = config('services.gemini.embedding_model');

        $hashes = array_map(fn (string $text) => EmbeddingCache::hashFor($model, $text), $texts);
        $cached = EmbeddingCache::whereIn('hash', $hashes)->pluck('embedding', 'hash')->all();

        // Unique texts not in the cache yet, keyed by hash.
        $missing = [];
        foreach ($texts as $i => $text) {
            if (! isset($cached[$hashes[$i]])) {
                $missing[$hashes[$i]] = $text;
            }
        }

        foreach ($this->batches($missing) as $batch) {
            $this->waitForTokenBudget($this->estimateTokens($batch));
            $embeddings = $this->requestEmbeddings(array_values($batch));

            foreach (array_keys($batch) as $j => $hash) {
                EmbeddingCache::create(['hash' => $hash, 'model' => $model, 'embedding' => $embeddings[$j]]);
                $cached[$hash] = $embeddings[$j];
            }
        }

        return array_map(fn (string $hash) => $cached[$hash], $hashes);
    }

    /**
     * Split texts into batches of at most MAX_BATCH_SIZE texts and
     * TOKENS_PER_MINUTE estimated tokens, keeping their keys.
     *
     * @param  array<string, string>  $texts
     * @return array<int, array<string, string>>
     */
    private function batches(array $texts): array
    {
        $batches = [];
        $current = [];
        $tokens = 0;

        foreach ($texts as $key => $text) {
            $textTokens = $this->estimateTokens([$text]);

            if ($current !== [] && (count($current) >= self::MAX_BATCH_SIZE || $tokens + $textTokens > self::TOKENS_PER_MINUTE)) {
                $batches[] = $current;
                $current = [];
                $tokens = 0;
            }

            $current[$key] = $text;
            $tokens += $textTokens;
        }

        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    /**
     * @param  array<array-key, string>  $texts
     */
    private function estimateTokens(array $texts): int
    {
        return (int) ceil(array_sum(array_map('mb_strlen', $texts)) / self::CHARS_PER_TOKEN);
    }

    /**
     * Pause until sending this many tokens keeps the last 60 seconds within
     * TOKENS_PER_MINUTE.
     */
    private function waitForTokenBudget(int $tokens): void
    {
        while (true) {
            $now = $this->now();
            $this->sent = array_values(array_filter($this->sent, fn (array $s) => $now - $s['at'] < 60));

            if (array_sum(array_column($this->sent, 'tokens')) + $tokens <= self::TOKENS_PER_MINUTE) {
                break;
            }

            $this->pause((int) ceil(60 - ($now - $this->sent[0]['at'])) + 1);
        }

        $this->sent[] = ['at' => $this->now(), 'tokens' => $tokens];
    }

    /**
     * Sleep between rate-limited requests (overridden in tests, with now()).
     */
    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    protected function now(): float
    {
        return microtime(true);
    }

    /**
     * Embed one batch (at most MAX_BATCH_SIZE texts) in a single API call.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    private function requestEmbeddings(array $texts): array
    {
        $apiKey = config('services.gemini.api_key');

        if (empty($apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        $model = config('services.gemini.embedding_model');

        $requests = array_map(fn (string $text) => [
            'model' => "models/{$model}",
            'content' => ['parts' => [['text' => $text]]],
        ], $texts);

        // Gemini's quota is per minute (100 texts and 30,000 tokens/min on the
        // free tier), so on a 429 wait as long as the API asks and try again
        // instead of failing the whole document. A daily quota (1,000
        // texts/day on the free tier) won't reset within minutes, so don't
        // wait on that one.
        for ($attempt = 1; ; $attempt++) {
            $response = Http::withHeader('x-goog-api-key', $apiKey)
                ->timeout(60)
                ->post(sprintf(self::ENDPOINT, $model), [
                    'requests' => $requests,
                ]);

            if ($response->status() !== 429
                || $this->isDailyQuotaExceeded($response)
                || $attempt >= self::MAX_RATE_LIMIT_RETRIES) {
                break;
            }

            $this->pause($this->retryDelay($response));
        }

        if ($response->failed()) {
            Log::error('Gemini embeddings request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($this->isDailyQuotaExceeded($response)) {
                throw new RuntimeException('The daily embeddings quota has been used up. Already-embedded text is saved; please try again tomorrow to continue.');
            }

            throw new RuntimeException('Failed to generate embeddings. Please try again later.');
        }

        // The API returns embeddings in the same order as the input requests.
        return collect($response->json('embeddings'))
            ->pluck('values')
            ->all();
    }

    /**
     * Seconds the API asks us to wait before retrying (its RetryInfo detail,
     * e.g. "36s"), or a full minute when it doesn't say.
     */
    private function retryDelay(Response $response): int
    {
        $delay = collect($response->json('error.details', []))->firstWhere('@type', 'type.googleapis.com/google.rpc.RetryInfo')['retryDelay'] ?? null;

        return $delay !== null ? (int) ceil((float) $delay) + 2 : self::RATE_LIMIT_WAIT_SECONDS;
    }

    /**
     * Whether a 429 response is for a per-day quota (as opposed to per-minute).
     */
    private function isDailyQuotaExceeded(Response $response): bool
    {
        return $response->status() === 429 && str_contains($response->body(), 'PerDay');
    }
}
