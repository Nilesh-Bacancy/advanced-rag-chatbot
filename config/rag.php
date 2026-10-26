<?php

/*
|--------------------------------------------------------------------------
| RAG pipeline settings
|--------------------------------------------------------------------------
|
| Each retrieval improvement can be switched on or off independently, so
| `php artisan rag:eval` can measure what each one contributes (turn them
| all off to reproduce the original "vector search only" baseline).
|
*/

return [

    'retrieval' => [
        // Chunks passed to the LLM as context.
        'top_n' => (int) env('RAG_TOP_N', 5),

        // Candidates gathered from each search before fusion/reranking.
        'candidates' => (int) env('RAG_CANDIDATES', 20),

        // Combine vector search with MySQL FULLTEXT keyword search.
        'hybrid' => (bool) env('RAG_HYBRID', true),

        // When a question names a section (e.g. "Chapter 3", "South Asia"),
        // only search chunks from that section.
        'section_filter' => (bool) env('RAG_SECTION_FILTER', true),

        // Let the LLM pick the most relevant chunks from the candidates.
        'rerank' => (bool) env('RAG_RERANK', true),

        // How many fused candidates the reranker chooses from.
        'rerank_candidates' => (int) env('RAG_RERANK_CANDIDATES', 15),
    ],

    'chunking' => [
        'size' => 1000,
        'overlap' => 200,
    ],

    'profiles' => [
        // Documents at least this long with a bookmark outline are treated as
        // long, structured reports.
        'structured_min_pages' => 100,

        // Documents this short are treated as visual fact sheets: cheap
        // enough to run vision extraction on every page.
        'visual_max_pages' => 20,

        // Resolution used when rendering pages for vision extraction.
        'vision_dpi' => 110,
    ],

];
