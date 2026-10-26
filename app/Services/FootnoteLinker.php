<?php

namespace App\Services;

/**
 * Attaches footnote text to the chunks that cite it.
 *
 * In reports like Apple's, a claim on page 2 ("reduced emissions by more than
 * 20 percent⁷") is qualified by a footnote printed pages later in an
 * "Endnotes" section. Chunked separately, the claim and its footnote are
 * unlikely to be retrieved together, so the answer loses the conditions the
 * footnote puts on the claim. Appending "[Footnote 7] ..." to the chunk that
 * contains the marker keeps the two together for both search and the LLM.
 */
class FootnoteLinker
{
    /** Longest footnote text appended to a chunk. */
    private const MAX_FOOTNOTE_LENGTH = 700;

    /** Markers following text shorter than this (e.g. a chart label) are linked by page only. */
    private const MIN_ANCHOR_LENGTH = 12;

    /**
     * Footnote texts keyed by number, read from an "Endnotes"/"Notes"/
     * "Footnotes" section (which may continue over several pages).
     *
     * @param  array<int, string>  $pages  Page text keyed by page number.
     * @return array<int, string>
     */
    public function definitions(array $pages): array
    {
        $pages = $this->withoutRunningHeaders($pages);
        $definitions = [];
        $current = null;
        $inNotes = false;

        foreach ($pages as $text) {
            foreach (preg_split('/\n/', $text) as $line) {
                $line = trim($line);

                if (! $inNotes) {
                    $inNotes = (bool) preg_match('/^(End\s*notes|Foot\s*notes|Notes)$/i', $line);

                    continue;
                }

                // Footnotes are numbered in sequence, which tells a new footnote
                // ("8 We calculate...") apart from a line that merely starts
                // with a number ("15 percent recycled...").
                if (preg_match('/^(\d{1,2})\s+(\S.*)$/u', $line, $match) && (int) $match[1] === ($current ?? 0) + 1) {
                    $current = (int) $match[1];
                    $definitions[$current] = $match[2];
                } elseif ($current !== null && $line !== '') {
                    $definitions[$current] .= ' '.$line;
                }
            }
        }

        return array_map(fn (string $text) => mb_strimwidth($text, 0, self::MAX_FOOTNOTE_LENGTH, '...'), $definitions);
    }

    /**
     * Append the footnotes each chunk cites to its content.
     *
     * @param  array<int, array{content: string, page_start: int, page_end: int}>  $chunks
     * @param  array<int, array<int, array{marker: int, after: string}>>  $markers  keyed by page (see PdfLayoutService::footnoteMarkers())
     * @param  array<int, string>  $definitions
     * @return array<int, array{content: string, page_start: int, page_end: int}>
     */
    public function link(array $chunks, array $markers, array $definitions): array
    {
        if ($markers === [] || $definitions === []) {
            return $chunks;
        }

        foreach ($chunks as &$chunk) {
            $content = $this->normalize($chunk['content']);
            $cited = [];

            for ($page = $chunk['page_start']; $page <= $chunk['page_end']; $page++) {
                foreach ($markers[$page] ?? [] as $marker) {
                    $anchor = $this->normalize($marker['after']);

                    // Link when the chunk contains the text the marker follows
                    // (its last few words, as a chunk may start mid-line).
                    if (mb_strlen($anchor) < self::MIN_ANCHOR_LENGTH
                        || str_contains($content, mb_substr($anchor, -40))) {
                        $cited[$marker['marker']] = true;
                    }
                }
            }

            ksort($cited);

            foreach (array_keys($cited) as $number) {
                if (isset($definitions[$number])) {
                    $chunk['content'] .= "\n\n[Footnote {$number}] {$definitions[$number]}";
                }
            }
        }

        return $chunks;
    }

    /**
     * Remove a page header/footer line (like "iMac | Product Environmental
     * Report 11") that repeats on most pages, so it isn't read as part of a
     * footnote that continues onto the next page.
     *
     * @param  array<int, string>  $pages
     * @return array<int, string>
     */
    private function withoutRunningHeaders(array $pages): array
    {
        $key = fn (string $line) => trim(preg_replace('/\d+/', '', $line));
        $counts = array_count_values(array_map(fn (string $text) => $key(strtok($text, "\n")), $pages));

        return array_map(function (string $text) use ($counts, $key, $pages) {
            $first = strtok($text, "\n");

            return $first !== false && $counts[$key($first)] >= max(2, count($pages) / 2)
                ? (string) substr($text, strlen($first) + 1)
                : $text;
        }, $pages);
    }

    private function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
