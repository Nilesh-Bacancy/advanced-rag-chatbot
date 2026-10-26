<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use SimpleXMLElement;

/**
 * Reads layout information that plain text extraction loses, using poppler's
 * `pdftohtml -xml` (part of poppler-utils, alongside pdftotext):
 *
 *   - the bookmark outline (chapter/section titles and their pages), used for
 *     section-aware chunk metadata; and
 *   - superscript footnote markers (small digit-only text placed right after
 *     body text), used to link footnotes to the sentences that cite them.
 */
class PdfLayoutService
{
    /**
     * The PDF's bookmark outline, flattened in reading order.
     *
     * @return array<int, array{title: string, page: int, level: int}>
     */
    public function outline(string $absolutePath): array
    {
        // The outline is written out in full even when only page 1 is converted.
        $xml = $this->toXml($absolutePath, 1, 1);

        if (! isset($xml->outline)) {
            return [];
        }

        $items = [];
        $this->collectOutline($xml->outline, 0, $items);

        return $items;
    }

    /**
     * Superscript footnote markers on each page, with the text they follow.
     *
     * @return array<int, array<int, array{marker: int, after: string}>> keyed by page number
     */
    public function footnoteMarkers(string $absolutePath): array
    {
        $xml = $this->toXml($absolutePath);
        $markers = [];

        foreach ($xml->page as $page) {
            $texts = [];
            foreach ($page->text as $text) {
                $texts[] = [
                    'text' => trim((string) $text),
                    'top' => (int) $text['top'],
                    'left' => (int) $text['left'],
                    'height' => (int) $text['height'],
                ];
            }

            $bodyHeight = $this->typicalHeight($texts);

            foreach ($texts as $i => $text) {
                $previous = $texts[$i - 1] ?? null;

                // A marker is a 1-2 digit number in clearly smaller type, sitting
                // on the same line as (and directly after) the text before it,
                // and raised above that line's middle (a subscript, like the
                // "2" in CO2, sits below it instead).
                if ($previous === null
                    || ! preg_match('/^\d{1,2}$/', $text['text'])
                    || $text['height'] > $bodyHeight * 0.8
                    || abs($text['top'] - $previous['top']) > $bodyHeight
                    || $text['left'] < $previous['left']
                    || mb_strlen($previous['text']) < 3
                    || $text['top'] + $text['height'] / 2 >= $previous['top'] + $previous['height'] / 2) {
                    continue;
                }

                $markers[(int) $page['number']][] = [
                    'marker' => (int) $text['text'],
                    'after' => $previous['text'],
                ];
            }
        }

        return $markers;
    }

    /**
     * Number of pages in the PDF.
     */
    public function pageCount(string $absolutePath): int
    {
        $result = Process::run(['pdfinfo', $absolutePath]);

        if ($result->failed() || ! preg_match('/^Pages:\s+(\d+)/m', $result->output(), $match)) {
            throw new RuntimeException('Could not read the PDF page count (is poppler-utils installed?).');
        }

        return (int) $match[1];
    }

    /**
     * Render one page to a PNG image and return its bytes.
     */
    public function renderPage(string $absolutePath, int $page, int $dpi): string
    {
        $prefix = tempnam(sys_get_temp_dir(), 'page');

        try {
            $result = Process::run([
                'pdftoppm', '-png', '-singlefile', '-r', (string) $dpi,
                '-f', (string) $page, '-l', (string) $page,
                $absolutePath, $prefix,
            ]);

            if ($result->failed() || ! is_file("{$prefix}.png")) {
                throw new RuntimeException("Could not render page {$page} (is poppler-utils installed?).");
            }

            return file_get_contents("{$prefix}.png");
        } finally {
            @unlink($prefix);
            @unlink("{$prefix}.png");
        }
    }

    /**
     * @param  array<int, array{title: string, page: int, level: int}>  $items
     */
    private function collectOutline(SimpleXMLElement $outline, int $level, array &$items): void
    {
        foreach ($outline->children() as $child) {
            if ($child->getName() === 'item') {
                $items[] = [
                    'title' => trim(preg_replace('/\s+/u', ' ', (string) $child)),
                    'page' => (int) $child['page'],
                    'level' => $level,
                ];
            } elseif ($child->getName() === 'outline') {
                $this->collectOutline($child, $level + 1, $items);
            }
        }
    }

    /**
     * The most common text height on a page, i.e. the body text size.
     *
     * @param  array<int, array{height: int}>  $texts
     */
    private function typicalHeight(array $texts): int
    {
        $counts = array_count_values(array_column($texts, 'height'));
        arsort($counts);

        return (int) (array_key_first($counts) ?? 12);
    }

    private function toXml(string $absolutePath, ?int $firstPage = null, ?int $lastPage = null): SimpleXMLElement
    {
        $command = ['pdftohtml', '-xml', '-i', '-q', '-stdout', '-nodrm'];

        if ($firstPage !== null) {
            array_push($command, '-f', (string) $firstPage, '-l', (string) $lastPage);
        }

        $result = Process::timeout(300)->run([...$command, $absolutePath]);

        if ($result->failed()) {
            throw new RuntimeException('Could not read the PDF layout (is poppler-utils installed?).');
        }

        // Some PDFs produce control characters that aren't valid in XML.
        $output = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $result->output());
        $xml = simplexml_load_string($output, options: LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE);

        if ($xml === false) {
            throw new RuntimeException('Could not parse the PDF layout.');
        }

        return $xml;
    }
}
