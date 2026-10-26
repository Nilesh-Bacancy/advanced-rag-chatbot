<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Extracts information that lives in a page's visuals (charts, tables,
 * icon call-outs) by sending an image of the page to Gemini.
 *
 * Text extraction reads a chart as a jumble of labels ("445 kg", "–99 kg",
 * "Baseline emissions7") without saying how they relate; a vision model can
 * read the chart the way a person does and describe it in sentences, which
 * then become searchable chunks of their own.
 */
class VisionExtractor
{
    private const NOTHING_FOUND = 'NONE';

    public function __construct(
        private readonly PdfLayoutService $layout,
        private readonly LlmService $llm,
    ) {}

    /**
     * Describe the visual content of each page.
     *
     * @return array<int, string> keyed by page number; pages with no visual content are left out
     */
    public function extract(string $absolutePath, int $pageCount): array
    {
        $descriptions = [];

        for ($page = 1; $page <= $pageCount; $page++) {
            try {
                $image = $this->layout->renderPage($absolutePath, $page, config('rag.profiles.vision_dpi'));
                $description = trim($this->llm->generate($this->prompt(), "This is page {$page}.", [
                    ['mime_type' => 'image/png', 'data' => $image],
                ]));
            } catch (Throwable $e) {
                // Vision only adds to the text we already have, so one failed
                // page shouldn't fail the whole document.
                Log::warning('Vision extraction failed for a page', ['page' => $page, 'error' => $e->getMessage()]);

                continue;
            }

            if ($description !== '' && ! str_starts_with(strtoupper($description), self::NOTHING_FOUND)) {
                $descriptions[$page] = $description;
            }
        }

        return $descriptions;
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
            You read one page of a PDF report and extract ONLY the information
            shown visually, which plain text extraction would lose:

            - Charts and graphs: the title, what is measured, every labelled
              value with its unit, and what each bar/segment/line represents,
              written as full sentences (e.g. "Baseline emissions are 445 kg
              CO2e; emissions reductions are -99 kg CO2e, giving 346 kg CO2e").
            - Tables: reproduce them as Markdown tables with every row and
              column, keeping units.
            - Icon call-outs, infographics and highlighted figures: each figure
              together with what it refers to.
            - Footnote markers attached to figures (e.g. "Baseline emissions,
              see footnote 7").

            Do not repeat ordinary paragraphs of body text. Do not add any
            information that is not on the page, and do not guess unreadable
            values. Write plain text (Markdown only for tables).

            If the page has no charts, tables or call-outs, reply with exactly: NONE
            PROMPT;
    }
}
