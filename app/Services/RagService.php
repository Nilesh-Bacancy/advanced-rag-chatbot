<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Collection;

/**
 * Orchestrates the full RAG (Retrieval-Augmented Generation) flow for
 * answering one question about one document:
 *
 *   Question -> Embedding -> Vector Search -> Relevant Chunks
 *            -> Prompt + Context -> LLM -> Answer
 *
 * "Retrieval" is finding the relevant chunks (steps 1-3 below).
 * "Augmentation" is inserting those chunks into the prompt (step 4).
 * "Generation" is the LLM producing the final answer (step 5).
 */
class RagService
{
    public function __construct(
        private readonly EmbeddingService $embeddingService,
        private readonly RetrievalService $retrievalService,
        private readonly LlmService $llmService,
    ) {}

    /**
     * Answer a question about a document, grounded only in that document's content.
     */
    public function ask(Document $document, string $question): string
    {
        return $this->answer($document, $question)['answer'];
    }

    /**
     * Same as ask(), but also returns the chunks the answer was based on and
     * the sections the question was matched to, so callers (e.g. the
     * evaluation command) can check what was retrieved.
     *
     * @return array{answer: string, chunks: Collection<int, DocumentChunk>, sections: array<int, string>}
     */
    public function answer(Document $document, string $question): array
    {
        // 1-2. Turn the question into an embedding and retrieve relevant chunks
        // (section filter, hybrid search and reranking: see RetrievalService).
        $questionEmbedding = $this->embeddingService->embed($question);
        ['chunks' => $relevantChunks, 'sections' => $sections] = $this->retrievalService->retrieve($document, $question, $questionEmbedding);

        if ($relevantChunks->isEmpty()) {
            return [
                'answer' => "I couldn't find anything related to that in the uploaded document.",
                'chunks' => $relevantChunks,
                'sections' => $sections,
            ];
        }

        // 3-4. Augment: build the context block from the retrieved chunks,
        // labelling each with its page(s) and section so the answer can cite
        // them and keep facts from different sections apart.
        $context = $relevantChunks
            ->map(fn (DocumentChunk $chunk) => '[Source: '.$this->retrievalService->sourceLabel($chunk)."]\n".$chunk->content)
            ->implode("\n\n---\n\n");

        $systemPrompt = <<<'PROMPT'
            You are a document assistant. Answer the user's question using ONLY
            the provided document context below. Do not use any outside knowledge.

            If the context does not contain the answer (for example, the question
            is about a topic the document doesn't cover), do not guess or answer
            from general knowledge. Instead reply in this form:
            "This document doesn't contain any information about <topic>." followed
            by one short sentence saying what the document does cover, so the
            user knows what they can ask.

            If the message is a greeting or small talk, reply briefly and invite
            the user to ask a question about the document.

            Each context passage starts with its source page and, where known,
            its section, e.g. [Source: p. 96 | Ch. 2 Regional Outlooks > South Asia].
            Passages may come from different sections (e.g. different regions,
            chapters or products): use only those about what the question asks,
            and never attribute a figure from one section to another.
            Some passages include footnotes ([Footnote 7] ...) that qualify a
            claim; include those conditions when they matter for the answer.

            End your answer with the page(s) you used, in the form
            "(Source: p. 12)" or "(Sources: p. 12, pp. 40-41)". Only cite pages
            whose passages actually support the answer.

            Write in plain text: no Markdown symbols such as **, __ or #. Use
            simple numbered or "-" lists where a list helps.
            PROMPT;

        $userPrompt = <<<PROMPT
            Document context:
            {$context}

            Question:
            {$question}
            PROMPT;

        // 5. Generate: ask the LLM to answer using only that context.
        return [
            'answer' => $this->llmService->generate($systemPrompt, $userPrompt),
            'chunks' => $relevantChunks,
            'sections' => $sections,
        ];
    }
}
