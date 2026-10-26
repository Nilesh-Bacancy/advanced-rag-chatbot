<?php

namespace App\Services;

use App\Models\Document;

/**
 * Turns a PDF into chunks ready for embedding, choosing the processing
 * strategy that suits the document's shape (its "profile"):
 *
 *   narrative   Mostly running text (e.g. an annual report).
 *               -> sentence-aware chunks, footnotes linked to their claims.
 *   structured  Long report with a chapter/section outline.
 *               -> chunks tagged with their section path, so retrieval can
 *                  tell sections apart (see RagService).
 *   visual      Short document whose facts sit in charts, tables, call-outs.
 *               -> sentence-aware chunks, footnotes linked, plus vision
 *                  extraction of every page (affordable because it's short).
 *
 * Section paths are added whenever the PDF has a bookmark outline.
 */
class IngestionService
{
    public function __construct(
        private readonly DocumentParserService $parser,
        private readonly PdfLayoutService $layout,
        private readonly ChunkingService $chunker,
        private readonly SectionMapper $sections,
        private readonly FootnoteLinker $footnotes,
        private readonly VisionExtractor $vision,
    ) {}

    /**
     * @return array{
     *     profile: string,
     *     page_count: int,
     *     chunks: array<int, array{content: string, page_start: int, page_end: int, section_path: ?string, chunk_type: string}>
     * }
     */
    public function prepare(string $absolutePath): array
    {
        $pageCount = $this->layout->pageCount($absolutePath);
        $outline = $this->layout->outline($absolutePath);
        $profile = $this->detectProfile($pageCount, $outline);
        $pages = $this->parser->extractPages($absolutePath);

        // Structured reports keep fixed-size chunks: their structure is
        // handled at retrieval time through section paths. (It also keeps
        // the chunk text, and so the cached embeddings, of this 200-page
        // document stable, which matters on the free-tier embedding quota.)
        $strategy = $profile === Document::PROFILE_STRUCTURED
            ? ChunkingService::STRATEGY_FIXED
            : ChunkingService::STRATEGY_SENTENCE;

        $chunks = $this->chunker->chunkPages($pages, $strategy, config('rag.chunking.size'), config('rag.chunking.overlap'));

        if ($profile !== Document::PROFILE_STRUCTURED) {
            $chunks = $this->footnotes->link(
                $chunks,
                $this->layout->footnoteMarkers($absolutePath),
                $this->footnotes->definitions($pages),
            );
        }

        $chunks = array_map(fn (array $chunk) => [...$chunk, 'chunk_type' => 'text'], $chunks);

        if ($profile === Document::PROFILE_VISUAL) {
            array_push($chunks, ...$this->visualChunks($absolutePath, $pageCount));
        }

        $pagePaths = $this->sections->pagePaths($outline, $pageCount);

        foreach ($chunks as &$chunk) {
            $chunk['section_path'] = isset($pagePaths[$chunk['page_start']])
                ? mb_strimwidth($pagePaths[$chunk['page_start']], 0, 255)
                : null;
        }

        return ['profile' => $profile, 'page_count' => $pageCount, 'chunks' => $chunks];
    }

    /**
     * @param  array<int, array{title: string, page: int, level: int}>  $outline
     */
    private function detectProfile(int $pageCount, array $outline): string
    {
        if ($pageCount >= config('rag.profiles.structured_min_pages') && count($outline) >= 5) {
            return Document::PROFILE_STRUCTURED;
        }

        if ($pageCount <= config('rag.profiles.visual_max_pages')) {
            return Document::PROFILE_VISUAL;
        }

        return Document::PROFILE_NARRATIVE;
    }

    /**
     * Chunks describing each page's charts, tables and call-outs.
     *
     * @return array<int, array{content: string, page_start: int, page_end: int, chunk_type: string}>
     */
    private function visualChunks(string $absolutePath, int $pageCount): array
    {
        $chunks = [];

        foreach ($this->vision->extract($absolutePath, $pageCount) as $page => $description) {
            // A table can be long; keep pieces a reasonable size, and say on
            // every piece what it is so it reads correctly on its own.
            foreach ($this->chunker->chunkBySentence($description, 1500, 0) as $piece) {
                $chunks[] = [
                    'content' => "Charts, tables and call-outs on page {$page}:\n{$piece}",
                    'page_start' => $page,
                    'page_end' => $page,
                    'chunk_type' => 'visual',
                ];
            }
        }

        return $chunks;
    }
}
