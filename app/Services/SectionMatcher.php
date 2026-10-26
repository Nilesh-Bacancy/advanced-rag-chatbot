<?php

namespace App\Services;

use App\Models\Document;

/**
 * Finds the document sections a question explicitly refers to, e.g.
 * "What does Chapter 3 say..." -> "Ch. 3 A Rising Challenge: ..." or
 * "...East Asia and Pacific compare with Sub-Saharan Africa?" -> both regions.
 *
 * Searching only inside those sections stops chunks from a similar-sounding
 * section (another region's growth forecast) from crowding out the right one.
 */
class SectionMatcher
{
    /** Ignore aliases this short (compared without spaces); they match too easily. */
    private const MIN_ALIAS_LENGTH = 6;

    /**
     * Ignore sections covering more than this share of the document's chunks:
     * filtering to them would hardly narrow the search, and their titles
     * (often just the organisation's name) appear in many questions.
     */
    private const MAX_SECTION_SHARE = 0.4;

    /**
     * Titles of the sections the question names (as they appear in section paths).
     *
     * @return array<int, string>
     */
    public function match(Document $document, string $question): array
    {
        $chunkCounts = $document->chunks()
            ->reorder()
            ->whereNotNull('section_path')
            ->selectRaw('section_path, count(*) as total')
            ->groupBy('section_path')
            ->pluck('total', 'section_path');

        $totalChunks = max(1, $document->chunks()->count());

        // Chunk count per individual title (a title appears in many paths).
        $titles = [];
        foreach ($chunkCounts as $path => $count) {
            foreach (explode(SectionMapper::PATH_SEPARATOR, $path) as $title) {
                $titles[$title] = ($titles[$title] ?? 0) + $count;
            }
        }

        $normalizedQuestion = $this->normalize($question);
        $matched = [];

        foreach ($titles as $title => $count) {
            if ($count / $totalChunks > self::MAX_SECTION_SHARE) {
                continue;
            }

            foreach ($this->aliases($title) as $alias) {
                if (strlen($alias) >= self::MIN_ALIAS_LENGTH && str_contains($normalizedQuestion, $alias)) {
                    $matched[] = $title;

                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * Ways a question might refer to a section title.
     *
     * @return array<int, string>
     */
    private function aliases(string $title): array
    {
        $aliases = [$this->normalize($title)];

        // "Ch. 3 A Rising Challenge: ..." -> "chapter 3", "A Rising Challenge"
        // "Box 1.1 How much will AI ..."  -> "box 1.1"
        if (preg_match('/^(?:Ch\.?|Chapter)\s*(\d+)\s*(.*)$/iu', $title, $match)) {
            $aliases[] = $this->normalize("chapter {$match[1]}");
            $aliases[] = $this->normalize(preg_split('/[:.]/', $match[2])[0]);
        } elseif (preg_match('/^(Box|Annex|Table|Figure|Appendix)\s*([\d.]+)/iu', $title, $match)) {
            $aliases[] = $this->normalize("{$match[1]} {$match[2]}");
        }

        return array_values(array_filter($aliases));
    }

    /**
     * Lower-case and strip spaces/punctuation, so "Latin America and the
     * Caribbean" matches a bookmark spelled "Latin America andthe Caribbean".
     */
    private function normalize(string $text): string
    {
        return preg_replace('/[^\p{L}\p{N}.]+/u', '', mb_strtolower($text));
    }
}
