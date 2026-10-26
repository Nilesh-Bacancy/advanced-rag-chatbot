<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadDocumentRequest;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DocumentController extends Controller
{
    /**
     * List uploaded documents with their processing status, page count and
     * chunk counts (visual chunks are the ones read from page images).
     */
    public function index(): InertiaResponse
    {
        return Inertia::render('Documents/Index', [
            'documents' => Document::latest()
                ->withCount([
                    'chunks',
                    'chunks as visual_chunks_count' => fn ($query) => $query->where('chunk_type', 'visual'),
                ])
                ->get(),
        ]);
    }

    /**
     * Upload a new PDF and dispatch it for background processing.
     *
     *   Upload PDF -> Save document (status: pending) -> Dispatch ProcessDocumentJob
     */
    public function store(UploadDocumentRequest $request): RedirectResponse
    {
        $file = $request->file('document');

        // Never trust the original filename: generate our own storage name.
        $storedName = Str::uuid()->toString().'.pdf';
        $path = $file->storeAs('documents', $storedName, 'local');

        $document = Document::create([
            'name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $path,
            'status' => Document::STATUS_PENDING,
        ]);

        ProcessDocumentJob::dispatch($document);

        return redirect()->route('documents.index');
    }

    /**
     * Remove a document, its stored PDF, and everything derived from it
     * (chunks, conversations, messages cascade via foreign keys).
     */
    public function destroy(Document $document): RedirectResponse
    {
        Storage::disk('local')->delete($document->file_path);
        $document->delete();

        return redirect()->route('documents.index');
    }
}
