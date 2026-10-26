<?php

namespace App\Services;

/**
 * Turns a PDF's bookmark outline into a section path for every page, e.g.
 * page 96 of the World Bank report -> "Ch. 2 Regional Outlooks > South Asia".
 *
 * Chunks are tagged with the path of the page they start on, which lets
 * retrieval tell apart sections that use the same vocabulary (every region
 * talks about "growth forecasts") and lets answers say where a fact came from.
 */
class SectionMapper
{
    /** Separator between levels of a section path. */
    public const PATH_SEPARATOR = ' > ';

    private const MAX_DEPTH = 3;

    /**
     * Section path for each page that falls inside a bookmarked section.
     *
     * @param  array<int, array{title: string, page: int, level: int}>  $outline
     * @return array<int, string> keyed by page number
     */
    public function pagePaths(array $outline, int $pageCount): array
    {
        $items = $this->withLevels($outline);

        // Bookmarks are not always listed in page order.
        usort($items, fn (array $a, array $b) => $a['page'] <=> $b['page']);

        $paths = [];
        $stack = [];
        $next = 0;

        for ($page = 1; $page <= $pageCount; $page++) {
            // Open every section that starts on or before this page; a new
            // section closes any open section at the same or a deeper level.
            while ($next < count($items) && $items[$next]['page'] <= $page) {
                $item = $items[$next++];
                $stack = array_slice($stack, 0, $item['level']);
                $stack[$item['level']] = $item['title'];
            }

            if ($stack !== []) {
                $paths[$page] = implode(self::PATH_SEPARATOR, array_slice(array_values($stack), 0, self::MAX_DEPTH));
            }
        }

        return $paths;
    }

    /**
     * Clean up titles and make sure every item has a usable nesting level.
     *
     * Many PDFs (like the World Bank report) export a flat outline where
     * boxes and sub-sections sit at the same level as chapters. In that case,
     * infer the nesting: items that look like sub-parts (boxes, annex tables,
     * "-- Region" entries) belong to the chapter before them.
     *
     * @param  array<int, array{title: string, page: int, level: int}>  $outline
     * @return array<int, array{title: string, page: int, level: int}>
     */
    private function withLevels(array $outline): array
    {
        $isFlat = count(array_unique(array_column($outline, 'level'))) <= 1;
        $items = [];

        foreach ($outline as $item) {
            // Skip broken bookmarks (page 0) and file-name entries.
            if ($item['page'] < 1 || preg_match('/\.pdf$/i', $item['title'])) {
                continue;
            }

            $title = trim(preg_replace(['/\s*--\s*/u', '/\s+/u'], [' ', ' '], $item['title']));
            $level = $item['level'];

            if ($isFlat) {
                $level = str_contains($item['title'], '--') || preg_match('/^(Box|Annex|Table|Figure|Appendix)\b/i', $title) ? 1 : 0;
            }

            if ($title !== '') {
                $items[] = ['title' => $title, 'page' => $item['page'], 'level' => min($level, self::MAX_DEPTH - 1)];
            }
        }

        return $items;
    }
}
