<?php

namespace App\Services;

/**
 * RAG step 2: Chunk.
 *
 * LLMs and embedding models work best on small, focused pieces of text
 * rather than an entire document at once. We split the extracted text into
 * overlapping chunks so that:
 *   - each chunk is small enough to embed and to fit in a prompt, and
 *   - the overlap avoids cutting a sentence/idea in half between chunks.
 *
 * Two strategies are available:
 *   - fixed:    fixed-size character windows, cut at the last space. Simple,
 *               but a chunk can start or end mid-sentence.
 *   - sentence: whole sentences/paragraphs packed up to the size limit, with
 *               the overlap made of whole sentences too. Better for narrative
 *               text, where a fact and its context sit in the same sentence.
 */
class ChunkingService
{
    public const STRATEGY_FIXED = 'fixed';

    public const STRATEGY_SENTENCE = 'sentence';

    /**
     * Chunk a document given page by page, recording which PDF page(s) each
     * chunk came from.
     *
     * The pages are joined exactly as DocumentParserService::extractText()
     * joins them, so with the fixed strategy the chunks are identical to
     * chunk($parser->extractText()).
     *
     * @param  array<int, string>  $pages  Page text keyed by 1-based page number.
     * @return array<int, array{content: string, page_start: int, page_end: int}>
     */
    public function chunkPages(array $pages, string $strategy = self::STRATEGY_FIXED, int $chunkSize = 1000, int $overlap = 200): array
    {
        $text = implode(DocumentParserService::PAGE_SEPARATOR, $pages);

        // Byte offset in $text at which each page starts.
        $pageStarts = [];
        $offset = 0;
        foreach ($pages as $pageNumber => $pageText) {
            $pageStarts[$pageNumber] = $offset;
            $offset += strlen($pageText) + strlen(DocumentParserService::PAGE_SEPARATOR);
        }

        $chunks = $strategy === self::STRATEGY_SENTENCE
            ? $this->chunkBySentence($text, $chunkSize, $overlap)
            : $this->chunk($text, $chunkSize, $overlap);

        $result = [];
        $cursor = 0;

        foreach ($chunks as $content) {
            // Chunks are taken from $text in order, so search forward from
            // the previous chunk's start to find where this one sits.
            $position = strpos($text, $content, $cursor);
            if ($position === false) {
                $position = $cursor;
            }

            $result[] = [
                'content' => $content,
                'page_start' => $this->pageAt($pageStarts, $position),
                'page_end' => $this->pageAt($pageStarts, $position + strlen($content) - 1),
            ];

            $cursor = $position + 1;
        }

        return $result;
    }

    /**
     * Split text into a list of overlapping fixed-size chunks.
     *
     * @return array<int, string>
     */
    public function chunk(string $text, int $chunkSize = 1000, int $overlap = 200): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $chunks = [];
        $length = mb_strlen($text);
        $start = 0;

        while ($start < $length) {
            $end = min($start + $chunkSize, $length);
            $piece = mb_substr($text, $start, $end - $start);

            // Try not to cut a word in half: back off to the last whitespace
            // unless we've already reached the end of the text.
            if ($end < $length) {
                $lastSpace = mb_strrpos($piece, ' ');
                if ($lastSpace !== false && $lastSpace > 0) {
                    $piece = mb_substr($piece, 0, $lastSpace);
                }
            }

            $piece = trim($piece);
            if ($piece !== '') {
                $chunks[] = $piece;
            }

            // We've consumed the rest of the text: stop, otherwise the
            // overlap would make us re-emit shrinking tail fragments forever.
            if ($end >= $length) {
                break;
            }

            $advance = max(mb_strlen($piece), 1);
            // Only step back for overlap when the chunk is longer than the
            // overlap; otherwise a short chunk would advance just one
            // character and produce near-duplicate chunks.
            $start += $advance > $overlap ? $advance - $overlap : $advance;
        }

        return $chunks;
    }

    /**
     * Split text into chunks of whole sentences/paragraphs, each at most
     * $chunkSize bytes, overlapping by up to $overlap bytes of whole sentences.
     * A single sentence longer than $chunkSize (e.g. a table with no full
     * stops) falls back to fixed-size splitting.
     *
     * @return array<int, string>
     */
    public function chunkBySentence(string $text, int $chunkSize = 1000, int $overlap = 200): array
    {
        $boundaries = $this->sentenceBoundaries($text);
        $unitCount = count($boundaries) - 1;
        $chunks = [];
        $i = 0;

        while ($i < $unitCount) {
            // Take as many whole units as fit (always at least one).
            $j = $i + 1;
            while ($j < $unitCount && $boundaries[$j + 1] - $boundaries[$i] <= $chunkSize) {
                $j++;
            }

            $piece = substr($text, $boundaries[$i], $boundaries[$j] - $boundaries[$i]);

            if (strlen($piece) > $chunkSize) {
                array_push($chunks, ...$this->chunk($piece, $chunkSize, $overlap));
            } elseif (trim($piece) !== '') {
                $chunks[] = trim($piece);
            }

            if ($j >= $unitCount) {
                break;
            }

            // Start the next chunk with the last whole units that fit in the
            // overlap, but always move forward by at least one unit.
            $next = $j;
            while ($next - 1 > $i && $boundaries[$j] - $boundaries[$next - 1] <= $overlap) {
                $next--;
            }
            $i = $next;
        }

        return $chunks;
    }

    /**
     * Byte offsets where sentences/paragraphs start, plus the end of the text.
     *
     * @return array<int, int>
     */
    private function sentenceBoundaries(string $text): array
    {
        // A new unit starts after sentence-ending punctuation followed by
        // whitespace, at a blank line (paragraph break), or at a bullet.
        preg_match_all('/(?<=[.!?])["”’)]?\s+(?=\S)|\n\s*\n|\n(?=\s*[•▪\-–] )/u', $text, $matches, PREG_OFFSET_CAPTURE);

        $boundaries = [0];
        foreach ($matches[0] as [$match, $offset]) {
            $boundaries[] = $offset + strlen($match);
        }
        $boundaries[] = strlen($text);

        return array_values(array_unique($boundaries));
    }

    /**
     * The page number containing a byte offset.
     *
     * @param  array<int, int>  $pageStarts
     */
    private function pageAt(array $pageStarts, int $position): int
    {
        $page = array_key_first($pageStarts);

        foreach ($pageStarts as $pageNumber => $start) {
            if ($start > $position) {
                break;
            }
            $page = $pageNumber;
        }

        return $page;
    }
}
