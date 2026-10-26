<?php

namespace Tests\Unit;

use App\Services\FootnoteLinker;
use PHPUnit\Framework\TestCase;

class FootnoteLinkerTest extends TestCase
{
    public function test_definitions_are_read_from_an_endnotes_section_across_pages(): void
    {
        $pages = [
            1 => "Report | Page 1\nEmissions fell by 20 percent.\n15 percent of the content is recycled.",
            2 => "Report | Page 2\nEndnotes\n1 Measured against a\nbusiness-as-usual scenario.\n2 Based on ENERGY STAR.",
            3 => "Report | Page 3\nrequirements for computers.\n3 Prices vary.",
        ];

        $definitions = (new FootnoteLinker)->definitions($pages);

        $this->assertSame([
            1 => 'Measured against a business-as-usual scenario.',
            2 => 'Based on ENERGY STAR. requirements for computers.',
            3 => 'Prices vary.',
        ], $definitions);
    }

    public function test_numbered_lines_out_of_sequence_are_not_read_as_footnotes(): void
    {
        $pages = [1 => "Notes\n1 First note.\n15 percent recycled content.\n2 Second note."];

        $definitions = (new FootnoteLinker)->definitions($pages);

        $this->assertSame([1 => 'First note. 15 percent recycled content.', 2 => 'Second note.'], $definitions);
    }

    public function test_footnotes_are_appended_to_the_chunk_containing_the_marked_text(): void
    {
        $chunks = [
            ['content' => 'Intro text on page two.', 'page_start' => 2, 'page_end' => 2],
            ['content' => "We reduced emissions against the scenario as modeled by\nApple. More text.", 'page_start' => 2, 'page_end' => 2],
        ];
        $markers = [2 => [['marker' => 7, 'after' => 'scenario as modeled by Apple.']]];
        $definitions = [7 => 'Business-as-usual scenario assumptions.'];

        $linked = (new FootnoteLinker)->link($chunks, $markers, $definitions);

        $this->assertSame('Intro text on page two.', $linked[0]['content']);
        $this->assertStringEndsWith('[Footnote 7] Business-as-usual scenario assumptions.', $linked[1]['content']);
    }

    public function test_markers_after_short_labels_link_by_page(): void
    {
        $chunks = [['content' => 'Chart: 445 kg baseline.', 'page_start' => 2, 'page_end' => 3]];
        $markers = [3 => [['marker' => 11, 'after' => 'emissions']]];

        $linked = (new FootnoteLinker)->link($chunks, $markers, [11 => 'Too small to show.']);

        $this->assertStringContainsString('[Footnote 11] Too small to show.', $linked[0]['content']);
    }
}
