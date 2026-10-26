<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RAG step 5: Retrieval, in stages (each can be switched off in config/rag.php):
 *
 *   1. Section filter  If the question names a section ("Chapter 3", "South
 *                      Asia"), search only that section's chunks.
 *   2. Hybrid search   Vector search finds chunks with similar meaning;
 *                      FULLTEXT keyword search finds exact terms and numbers
 *                      ("FDsys", "ENERGY STAR", "115V") that embeddings blur.
 *                      The two rankings are merged with Reciprocal Rank Fusion.
 *   3. Rerank          The LLM reads the top candidates and picks the ones
 *                      that actually help answer the question.
 *
 * With everything switched off this is plain vector search, i.e. the
 * original baseline.
 */
class RetrievalService
{
    /** Standard Reciprocal Rank Fusion constant. */
    private const RRF_K = 60;

    /** Characters of each candidate shown to the reranker. */
    private const RERANK_PREVIEW_LENGTH = 800;

    public function __construct(
        private readonly VectorSearchService $vectorSearch,
        private readonly SectionMatcher $sectionMatcher,
        private readonly LlmService $llm,
    ) {}

    /**
     * @param  array<int, float>  $questionEmbedding
     * @return array{chunks: Collection<int, DocumentChunk>, sections: array<int, string>}
     */
    public function retrieve(Document $document, string $question, array $questionEmbedding): array
    {
        $topN = config('rag.retrieval.top_n');
        $candidateCount = config('rag.retrieval.candidates');

        $sections = config('rag.retrieval.section_filter') ? $this->sectionMatcher->match($document, $question) : [];
        $scope = $sections === [] ? null : fn (Builder $query) => $query->where(function ($query) use ($sections) {
            foreach ($sections as $title) {
                $query->orWhere('section_path', 'like', '%'.addcslashes($title, '%_\\').'%');
            }
        });

        $candidates = $this->vectorSearch->search($document, $questionEmbedding, $candidateCount, $scope);

        if (config('rag.retrieval.hybrid') && $this->supportsFullText()) {
            $candidates = $this->fuse($candidates, $this->keywordSearch($document, $question, $candidateCount, $scope));
        }

        $chunks = config('rag.retrieval.rerank')
            ? $this->rerank($question, $candidates->take(config('rag.retrieval.rerank_candidates'))->values(), $topN)
            : $candidates->take($topN)->values();

        return ['chunks' => $chunks, 'sections' => $sections];
    }

    /**
     * MySQL FULLTEXT search over chunk text and section titles.
     *
     * @return Collection<int, DocumentChunk>
     */
    private function keywordSearch(Document $document, string $question, int $limit, ?callable $scope): Collection
    {
        return $document->chunks()
            ->reorder()
            ->when($scope, $scope)
            ->whereFullText(['content', 'section_path'], $question)
            ->limit($limit)
            ->get();
    }

    /**
     * Whether the database can run FULLTEXT searches (not SQLite, which the
     * test suite uses; see the add_profile_to_documents_table migration).
     */
    private function supportsFullText(): bool
    {
        return in_array(DocumentChunk::query()->getConnection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true);
    }

    /**
     * Merge two rankings with Reciprocal Rank Fusion: each chunk scores
     * 1 / (k + rank) in every list it appears in, so chunks ranked well by
     * both searches rise to the top without having to compare their raw scores.
     *
     * @param  Collection<int, DocumentChunk>  ...$rankings
     * @return Collection<int, DocumentChunk>
     */
    private function fuse(Collection ...$rankings): Collection
    {
        $scores = [];
        $chunks = [];

        foreach ($rankings as $ranking) {
            foreach ($ranking->values() as $rank => $chunk) {
                $scores[$chunk->id] = ($scores[$chunk->id] ?? 0) + 1 / (self::RRF_K + $rank + 1);
                $chunks[$chunk->id] ??= $chunk;
            }
        }

        arsort($scores);

        return collect(array_keys($scores))->map(fn (int $id) => $chunks[$id])->values();
    }

    /**
     * Ask the LLM which candidates best answer the question, best first.
     * Falls back to the original order if the reranker fails.
     *
     * @param  Collection<int, DocumentChunk>  $candidates
     * @return Collection<int, DocumentChunk>
     */
    private function rerank(string $question, Collection $candidates, int $topN): Collection
    {
        if ($candidates->count() <= 1) {
            return $candidates;
        }

        $passages = $candidates->map(fn (DocumentChunk $chunk, int $i) => "[{$i}] (".$this->sourceLabel($chunk).")\n"
            .mb_strimwidth($chunk->content, 0, self::RERANK_PREVIEW_LENGTH, '...'))->implode("\n\n");

        $systemPrompt = <<<PROMPT
            You rank passages from a document by how useful they are for answering
            a question. Prefer passages that directly contain the facts or numbers
            asked for, from the section the question is about. Ignore passages
            about a different region, product, chapter or time period than asked.

            Reply with JSON only, no code fences: an array of the numbers of the
            (at most {$topN}) most useful passages, best first, e.g. [3, 0, 7].
            Return [] if none of them is relevant.
            PROMPT;

        try {
            $reply = $this->llm->generate($systemPrompt, "Question:\n{$question}\n\nPassages:\n{$passages}");
            $order = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $reply)), true);
        } catch (Throwable $e) {
            Log::warning('Reranking failed, using search order', ['error' => $e->getMessage()]);
            $order = null;
        }

        if (! is_array($order)) {
            return $candidates->take($topN)->values();
        }

        $picked = collect($order)
            ->filter(fn ($i) => is_int($i) && $candidates->has($i))
            ->unique()
            ->take($topN);

        // The reranker's picks go first; remaining slots are filled in search
        // order. Dropping the rest outright hurts recall: the evaluation
        // showed the reranker discarding a chunk of jumbled chart labels that
        // held the answer. If nothing was picked, this is just the search
        // order, and the answer step can say the document doesn't cover it.
        return $picked
            ->merge($candidates->keys()->diff($picked))
            ->take($topN)
            ->map(fn (int $i) => $candidates[$i])
            ->values();
    }

    /**
     * "p. 96 | Ch. 2 Regional Outlooks > South Asia" style label for a chunk.
     */
    public function sourceLabel(DocumentChunk $chunk): string
    {
        return collect([$chunk->pageLabel() ?? 'page unknown', $chunk->section_path])->filter()->implode(' | ');
    }
}
