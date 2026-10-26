<?php

namespace Tests\Unit;

use App\Services\SectionMapper;
use PHPUnit\Framework\TestCase;

class SectionMapperTest extends TestCase
{
    public function test_a_flat_outline_nests_boxes_and_regions_under_their_chapter(): void
    {
        // Shaped like the World Bank report's bookmarks: flat, and not in page order.
        $outline = [
            ['title' => 'Executive Summary', 'page' => 2, 'level' => 0],
            ['title' => 'Ch. 1 Global Outlook', 'page' => 3, 'level' => 0],
            ['title' => 'Box 1.1 How much will AI affect global growth?', 'page' => 4, 'level' => 0],
            ['title' => 'Ch. 2 Regional Outlooks', 'page' => 6, 'level' => 0],
            ['title' => ' -- Latin America andthe Caribbean', 'page' => 9, 'level' => 0],
            ['title' => ' -- East Asia and Pacific', 'page' => 7, 'level' => 0],
            ['title' => 'Ch. 3 Sovereign Debt', 'page' => 11, 'level' => 0],
        ];

        $paths = (new SectionMapper)->pagePaths($outline, 12);

        $this->assertArrayNotHasKey(1, $paths);
        $this->assertSame('Executive Summary', $paths[2]);
        $this->assertSame('Ch. 1 Global Outlook > Box 1.1 How much will AI affect global growth?', $paths[5]);
        $this->assertSame('Ch. 2 Regional Outlooks', $paths[6]);
        $this->assertSame('Ch. 2 Regional Outlooks > East Asia and Pacific', $paths[8]);
        $this->assertSame('Ch. 2 Regional Outlooks > Latin America andthe Caribbean', $paths[10]);
        $this->assertSame('Ch. 3 Sovereign Debt', $paths[12]);
    }

    public function test_a_nested_outline_keeps_its_own_levels(): void
    {
        $outline = [
            ['title' => 'Financial Statements', 'page' => 1, 'level' => 0],
            ['title' => 'Balance Sheet', 'page' => 2, 'level' => 1],
            ['title' => 'Notes', 'page' => 3, 'level' => 1],
        ];

        $paths = (new SectionMapper)->pagePaths($outline, 3);

        $this->assertSame('Financial Statements > Balance Sheet', $paths[2]);
        $this->assertSame('Financial Statements > Notes', $paths[3]);
    }

    public function test_broken_and_file_name_bookmarks_are_ignored(): void
    {
        $outline = [
            ['title' => 'FY 2018 Statements.pdf', 'page' => 0, 'level' => 0],
            ['title' => 'Overview', 'page' => 2, 'level' => 1],
        ];

        $paths = (new SectionMapper)->pagePaths($outline, 2);

        $this->assertArrayNotHasKey(1, $paths);
        $this->assertSame('Overview', $paths[2]);
    }
}
