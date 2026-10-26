<?php

namespace App\Console\Commands;

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ingests PDFs from the command line, running the same pipeline as an upload
 * through the UI (ProcessDocumentJob) but synchronously, so it's easy to
 * script and see the result. Handy for loading evaluation documents.
 */
class RagIngestCommand extends Command
{
    protected $signature = 'rag:ingest
        {paths* : PDF files to ingest}
        {--replace : Delete earlier documents with the same name first}';

    protected $description = 'Ingest PDF files through the RAG pipeline (same as uploading them)';

    public function handle(): int
    {
        $failed = false;

        foreach ($this->argument('paths') as $path) {
            if (! is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
                $this->error("Not a PDF file: {$path}");
                $failed = true;

                continue;
            }

            $name = pathinfo($path, PATHINFO_FILENAME);

            if ($this->option('replace')) {
                Document::where('name', $name)->get()->each(function (Document $document) {
                    Storage::disk('local')->delete($document->file_path);
                    $document->delete();
                });
            }

            $storedPath = 'documents/'.Str::uuid().'.pdf';
            Storage::disk('local')->put($storedPath, file_get_contents($path));

            $document = Document::create([
                'name' => $name,
                'original_filename' => basename($path),
                'file_path' => $storedPath,
                'status' => Document::STATUS_PENDING,
            ]);

            $this->line("Ingesting {$document->original_filename}...");
            $started = microtime(true);
            ProcessDocumentJob::dispatchSync($document);
            $document->refresh();

            if ($document->status !== Document::STATUS_COMPLETED) {
                $this->error("  Failed: {$document->error_message}");
                $failed = true;

                continue;
            }

            $this->info(sprintf(
                '  Done in %.0fs: profile %s, %d pages, %d chunks (%d with a section, %d visual)',
                microtime(true) - $started,
                $document->profile,
                $document->page_count,
                $document->chunks()->count(),
                $document->chunks()->whereNotNull('section_path')->count(),
                $document->chunks()->where('chunk_type', 'visual')->count(),
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
