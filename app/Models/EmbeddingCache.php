<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmbeddingCache extends Model
{
    protected $table = 'embedding_cache';

    protected $fillable = [
        'hash',
        'model',
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
     * The cache key for a piece of text embedded with a given model.
     */
    public static function hashFor(string $model, string $text): string
    {
        return hash('sha256', $model."\n".$text);
    }
}
