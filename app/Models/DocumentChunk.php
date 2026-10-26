<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    protected $fillable = [
        'document_id',
        'chunk_index',
        'page_start',
        'page_end',
        'section_path',
        'chunk_type',
        'content',
        'embedding',
    ];

    /**
     * Cast the embedding vector to/from a plain PHP array.
     */
    protected function casts(): array
    {
        return [
            'embedding' => 'array',
        ];
    }

    /**
     * Human-readable page reference for citations, e.g. "p. 5" or "pp. 5-6".
     */
    public function pageLabel(): ?string
    {
        if ($this->page_start === null) {
            return null;
        }

        return $this->page_end !== null && $this->page_end !== $this->page_start
            ? "pp. {$this->page_start}-{$this->page_end}"
            : "p. {$this->page_start}";
    }

    /**
     * The document this chunk was extracted from.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
