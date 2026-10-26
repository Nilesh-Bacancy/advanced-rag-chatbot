<?php

namespace Tests\Unit;

use App\Services\ChunkingService;
use PHPUnit\Framework\TestCase;

class ChunkingServiceTest extends TestCase
{
    public function test_sentence_chunks_start_and_end_on_sentence_boundaries(): void
    {
        $text = implode(' ', array_map(fn (int $i) => "Sentence number {$i} has a few words in it.", range(1, 40)));

        $chunks = (new ChunkingService)->chunkBySentence($text, 200, 60);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(200, strlen($chunk));
            $this->assertStringStartsWith('Sentence number', $chunk);
            $this->assertStringEndsWith('in it.', $chunk);
        }
    }

    public function test_sentence_chunks_overlap_by_whole_sentences(): void
    {
        $text = 'First sentence is here. Second sentence is here. Third sentence is here. Fourth sentence is here.';

        $chunks = (new ChunkingService)->chunkBySentence($text, 50, 25);

        $this->assertSame([
            'First sentence is here. Second sentence is here.',
            'Second sentence is here. Third sentence is here.',
            'Third sentence is here. Fourth sentence is here.',
        ], $chunks);
    }

    public function test_a_sentence_longer_than_the_chunk_size_is_split_into_fixed_pieces(): void
    {
        $text = 'Short one. '.str_repeat('word ', 100).'end.';

        $chunks = (new ChunkingService)->chunkBySentence($text, 120, 20);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(120, strlen($chunk));
        }
        $this->assertStringContainsString('end.', end($chunks));
    }

    public function test_chunks_are_mapped_to_the_pages_they_come_from(): void
    {
        $pages = [
            1 => 'Page one talks about libraries. GPO staff visited 166 libraries.',
            3 => 'Page three talks about govinfo. It replaced FDsys in December 2018.',
        ];

        $chunks = (new ChunkingService)->chunkPages($pages, ChunkingService::STRATEGY_SENTENCE, 80, 0);

        $this->assertSame(1, $chunks[0]['page_start']);
        $this->assertSame(1, $chunks[0]['page_end']);
        $this->assertSame(3, end($chunks)['page_start']);
        $this->assertStringContainsString('December 2018', end($chunks)['content']);
    }

    public function test_fixed_page_chunks_match_chunking_the_joined_text(): void
    {
        $pages = [1 => str_repeat('alpha beta gamma ', 50), 2 => str_repeat('delta epsilon ', 60)];
        $service = new ChunkingService;

        $byPage = array_column($service->chunkPages($pages), 'content');

        $this->assertSame($service->chunk(implode("\n\n", $pages)), $byPage);
    }

    public function test_a_chunk_shorter_than_the_overlap_does_not_produce_near_duplicate_chunks(): void
    {
        // The only space is early in the first window (as when a PDF separates
        // words with non-breaking spaces), so the first chunk is cut short,
        // shorter than the overlap. That used to advance one character at a
        // time and emit ~150 near-identical chunks.
        $text = str_repeat('a', 150).' '.str_repeat('b', 2000);

        $chunks = (new ChunkingService)->chunk($text, 1000, 200);

        $this->assertLessThan(10, count($chunks));
    }
}
