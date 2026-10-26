<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * RAG step 5: Vector search / Retrieval.
 *
 * Given the question's embedding, find the document chunks whose embeddings
 * are most "similar" in meaning, using cosine similarity (a number from -1
 * to 1; 1 means the two vectors point in exactly the same direction, i.e.
 * very similar meaning).
 *
 * This implementation is intentionally simple: embeddings are stored as
 * JSON in MySQL, and similarity is computed in PHP by comparing the
 * question's vector against every chunk's vector ("brute force"). That's
 * plenty fast for a handful of documents. If this ever needed to scale to
 * a huge number of chunks, only this class would need to change — e.g. to
 * use a real vector database with an index (pgvector, Pinecone, etc.) —
 * because the rest of the app only depends on this class's public method.
 */
class VectorSearchService
{
    /**
     * Find the top-N chunks of a document most relevant to a question embedding.
     *
     * An optional $scope callback narrows the chunks searched, e.g. to the
     * sections a question asks about.
     *
     * @param  array<int, float>  $questionEmbedding
     * @param  (callable(Builder): mixed)|null  $scope
     * @return Collection<int, DocumentChunk>
     */
    public function search(Document $document, array $questionEmbedding, int $topN = 5, ?callable $scope = null): Collection
    {
        return $document->chunks()
            ->when($scope, $scope)
            ->whereNotNull('embedding')
            ->get()
            ->map(function (DocumentChunk $chunk) use ($questionEmbedding) {
                $chunk->similarity = $this->cosineSimilarity($questionEmbedding, $chunk->embedding);

                return $chunk;
            })
            ->sortByDesc('similarity')
            ->take($topN)
            ->values();
    }

    /**
     * Cosine similarity between two equal-length vectors.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;

        foreach ($a as $i => $value) {
            $dotProduct += $value * ($b[$i] ?? 0);
            $magnitudeA += $value ** 2;
            $magnitudeB += ($b[$i] ?? 0) ** 2;
        }

        if ($magnitudeA === 0.0 || $magnitudeB === 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($magnitudeA) * sqrt($magnitudeB));
    }
}
