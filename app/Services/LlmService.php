<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * RAG step 6: Generation.
 *
 * Sends a prompt (built by RagService) to Google's Gemini generateContent
 * API and returns the generated answer text. This class knows nothing about
 * documents, chunks, or embeddings — it's a thin wrapper around "send a
 * prompt, get an answer back", which keeps it reusable and easy to test.
 */
class LlmService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private const MAX_RATE_LIMIT_WAITS = 3;

    /**
     * Send a system + user prompt to the LLM and return its answer text.
     *
     * Images (e.g. rendered PDF pages) can be attached to the user prompt as
     * [['mime_type' => 'image/png', 'data' => <raw bytes>], ...].
     *
     * @param  array<int, array{mime_type: string, data: string}>  $images
     */
    public function generate(string $systemPrompt, string $userPrompt, array $images = []): string
    {
        $apiKey = config('services.gemini.api_key');

        if (empty($apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        $models = array_unique(array_filter([
            config('services.gemini.chat_model'),
            config('services.gemini.chat_fallback_model'),
        ]));

        foreach ($models as $model) {
            try {
                $response = $this->request($apiKey, $model, $systemPrompt, $userPrompt, $images);

                // The free tier allows only a few requests per minute; when
                // that's hit, wait for the window the API asks for and retry.
                for ($wait = 1; $wait <= self::MAX_RATE_LIMIT_WAITS && $this->isPerMinuteLimit($response); $wait++) {
                    sleep($this->retryDelaySeconds($response));
                    $response = $this->request($apiKey, $model, $systemPrompt, $userPrompt, $images);
                }
            } catch (ConnectionException $e) {
                // An overloaded model can hang until the timeout instead of
                // returning a 503, so treat a timeout as a reason to fall back too.
                Log::error('Gemini generateContent request timed out', [
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($response->successful()) {
                return trim((string) $response->json('candidates.0.content.parts.0.text'));
            }

            Log::error('Gemini generateContent request failed', [
                'model' => $model,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            // Only an overloaded/rate-limited model is worth falling back
            // from; anything else (bad key, bad request) will fail the same
            // way on every model.
            if (! $this->isTransient($response->status())) {
                break;
            }
        }

        throw new RuntimeException('Failed to generate an answer. Please try again later.');
    }

    /**
     * Call generateContent on one model, retrying briefly when Gemini is
     * overloaded (these 503s usually clear within a few seconds).
     */
    private function request(string $apiKey, string $model, string $systemPrompt, string $userPrompt, array $images): Response
    {
        $parts = array_map(fn (array $image) => [
            'inline_data' => [
                'mime_type' => $image['mime_type'],
                'data' => base64_encode($image['data']),
            ],
        ], $images);
        $parts[] = ['text' => $userPrompt];

        return Http::withHeader('x-goog-api-key', $apiKey)
            ->timeout(60)
            ->retry(3, 1000, fn ($e) => $e instanceof RequestException && $this->isTransient($e->response->status()), throw: false)
            ->post(sprintf(self::ENDPOINT, $model), [
                'systemInstruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    ['role' => 'user', 'parts' => $parts],
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                ],
            ]);
    }

    private function isTransient(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /**
     * A 429 for a per-minute quota (worth waiting for), as opposed to a
     * per-day quota (which won't reset for hours).
     */
    private function isPerMinuteLimit(Response $response): bool
    {
        return $response->status() === 429 && ! str_contains($response->body(), 'PerDay');
    }

    /**
     * How long the API asked us to wait before retrying, within sane bounds.
     */
    private function retryDelaySeconds(Response $response): int
    {
        $delay = (string) collect($response->json('error.details', []))->pluck('retryDelay')->filter()->first();

        return max(5, min(65, (int) ceil((float) $delay) + 1));
    }
}
