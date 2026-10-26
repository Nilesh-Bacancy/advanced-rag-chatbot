<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Services\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetrievalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_question_naming_a_section_only_retrieves_chunks_from_it(): void
    {
        config([
            'rag.retrieval.section_filter' => true,
            'rag.retrieval.hybrid' => false,
            'rag.retrieval.rerank' => false,
        ]);

        $document = Document::factory()->create(['status' => Document::STATUS_COMPLETED]);

        $sections = [
            'Ch. 2 Regional Outlooks > South Asia',
            'Ch. 2 Regional Outlooks > East Asia and Pacific',
            'Ch. 2 Regional Outlooks > East Asia and Pacific',
            'Ch. 2 Regional Outlooks > Sub-Saharan Africa',
        ];

        foreach ($sections as $index => $path) {
            $document->chunks()->create([
                'chunk_index' => $index,
                'page_start' => $index + 1,
                'page_end' => $index + 1,
                'section_path' => $path,
                'chunk_type' => 'text',
                'content' => 'Growth is projected to edge down in 2026.',
                'embedding' => [1.0, 0.0],
            ]);
        }

        $result = app(RetrievalService::class)->retrieve($document, 'What is the growth outlook for South Asia?', [1.0, 0.0]);

        $this->assertSame(['South Asia'], $result['sections']);
        $this->assertSame(
            ['Ch. 2 Regional Outlooks > South Asia'],
            $result['chunks']->pluck('section_path')->unique()->values()->all(),
        );
    }
}
