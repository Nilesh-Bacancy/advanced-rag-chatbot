<?php

namespace App\Services;

use Smalot\PdfParser\Parser;

/**
 * RAG step 1: Extract text.
 *
 * A PDF is a binary format; before we can do anything useful with a document
 * (chunk it, embed it, search it) we need its plain text content.
 */
class DocumentParserService
{
    /**
     * Separator placed between pages when they are joined into one text
     * (the same one the PDF parser itself uses).
     */
    public const PAGE_SEPARATOR = "\n\n";

    /**
     * Extract the full plain-text content of a PDF file.
     */
    public function extractText(string $absolutePath): string
    {
        return trim(implode(self::PAGE_SEPARATOR, $this->extractPages($absolutePath)));
    }

    /**
     * Extract the text of each page, keyed by its 1-based PDF page number.
     * Pages without any text (e.g. full-page images) are left out.
     *
     * Joining these with PAGE_SEPARATOR gives exactly the same text as
     * extractText(), so chunks (and their cached embeddings) don't change
     * when page numbers are tracked.
     *
     * @return array<int, string>
     */
    public function extractPages(string $absolutePath): array
    {
        $parser = new Parser;
        $pdf = $parser->parseFile($absolutePath);

        $pages = [];

        foreach ($pdf->getPages() as $index => $page) {
            $text = trim($page->getText());

            if ($text === '') {
                continue;
            }

            // Normalise excessive blank lines/whitespace left behind by the parser.
            // Some PDFs use non-breaking spaces (U+00A0) between words; treat
            // them as normal spaces so chunking can split on word boundaries.
            $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
            $text = preg_replace('/\n{3,}/', "\n\n", $text);

            $pages[$index + 1] = $text;
        }

        return $pages;
    }
}
