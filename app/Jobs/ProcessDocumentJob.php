<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\EmbeddingService;
use App\Services\IngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs the document ingestion side of the RAG pipeline in the background,
 * so uploading a large PDF doesn't block the HTTP request:
 *
 *   Detect profile -> Extract text (+ layout, vision) -> Chunk -> Embed each chunk
 *   -> Store chunks -> Mark completed
 *
 * Dispatched from DocumentController after a document is uploaded and saved
 * with status "pending". Runs on the default queue connection (configured
 * via QUEUE_CONNECTION in .env — "database" by default, so `php artisan
 * queue:work` must be running; set it to "sync" to run this immediately
 * inline instead, with no worker needed).
 */
class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Seconds the job may run. A 200-page report takes several minutes
     * (embedding batches, rate-limit waits), far beyond the worker's 60s
     * default. The queue's retry_after (DB_QUEUE_RETRY_AFTER) must be
     * longer than this, or the job is picked up a second time while running.
     */
    public int $timeout = 1800;

    /** Skip jobs whose document was removed before the worker reached them. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(private readonly Document $document) {}

    public function handle(IngestionService $ingestion, EmbeddingService $embedder): void
    {
        $this->document->update(['status' => Document::STATUS_PROCESSING]);

        try {
            $absolutePath = Storage::disk('local')->path($this->document->file_path);

            // Parse and chunk with the strategy that suits this document's shape.
            ['profile' => $profile, 'page_count' => $pageCount, 'chunks' => $chunks] = $ingestion->prepare($absolutePath);

            if (empty($chunks)) {
                throw new \RuntimeException('No extractable text was found in this PDF.');
            }

            $this->document->update(['profile' => $profile, 'page_count' => $pageCount]);

            // Embed chunks in batched API calls rather than one per chunk.
            $embeddings = $embedder->embedBatch(array_column($chunks, 'content'));

            foreach ($chunks as $index => $chunk) {
                $this->document->chunks()->create([
                    'chunk_index' => $index,
                    'page_start' => $chunk['page_start'],
                    'page_end' => $chunk['page_end'],
                    'section_path' => $chunk['section_path'],
                    'chunk_type' => $chunk['chunk_type'],
                    'content' => $chunk['content'],
                    'embedding' => $embeddings[$index],
                ]);
            }

            $this->document->update(['status' => Document::STATUS_COMPLETED]);
        } catch (Throwable $e) {
            Log::error('Document processing failed', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
            ]);

            $this->document->update([
                'status' => Document::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Called when the worker kills the job (e.g. on timeout), which the
     * try/catch above can't see; without this the document would stay
     * "processing" forever.
     */
    public function failed(?Throwable $e): void
    {
        $this->document->update([
            'status' => Document::STATUS_FAILED,
            'error_message' => 'Processing was interrupted (it took too long or the worker stopped). Please try again.',
        ]);
    }
}
