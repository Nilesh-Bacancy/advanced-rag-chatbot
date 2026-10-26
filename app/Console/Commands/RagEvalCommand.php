<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\LlmService;
use App\Services\RagService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Evaluates the RAG pipeline against a fixed set of questions with known
 * answers (resources/eval/questions.json), so changes to parsing, chunking,
 * retrieval or prompting can be compared run to run.
 *
 * For each question it records two things:
 *   - Retrieval hit: did any retrieved chunk come from one of the pages the
 *     answer is actually on? (Skipped for unanswerable questions.)
 *   - Answer verdict: an LLM judge compares the answer to the expected
 *     answer and decides pass/fail.
 *
 * Every run is saved to storage/app/eval/ for before/after comparison.
 */
class RagEvalCommand extends Command
{
    protected $signature = 'rag:eval
        {--doc=* : Only evaluate questions for these document names}
        {--id=* : Only evaluate these question ids}
        {--top= : Number of chunks passed to the LLM (default: config rag.retrieval.top_n)}
        {--baseline : Plain vector search only (turns off hybrid search, section filter and reranking)}
        {--no-hybrid : Turn off keyword (FULLTEXT) search}
        {--no-section-filter : Turn off section filtering}
        {--no-rerank : Turn off LLM reranking}
        {--label= : Short label saved with the run, e.g. "baseline"}
        {--show-answers : Print each full answer}';

    protected $description = 'Run the evaluation questions through the RAG pipeline and score the answers';

    private const QUESTIONS_PATH = 'eval/questions.json';

    public function handle(RagService $rag, LlmService $llm): int
    {
        $this->applyRetrievalOptions();
        $this->line('Retrieval: '.json_encode(config('rag.retrieval')));

        $questions = $this->loadQuestions();

        if ($questions === []) {
            $this->error('No questions match the given filters.');

            return self::FAILURE;
        }

        $results = [];

        foreach ($questions as $question) {
            $this->line("<comment>[{$question['id']}]</comment> {$question['question']}");
            $results[] = $result = $this->evaluate($rag, $llm, $question);

            if ($this->option('show-answers')) {
                $this->line("  Expected: {$question['expected']}");
                $this->line("  Answer:   {$result['answer']}");
            }
        }

        $this->newLine();
        $this->table(
            ['ID', 'Kind', 'Sections matched', 'Retrieved pages', 'Retrieval', 'Answer', 'Judge reason'],
            array_map(fn (array $r) => [
                $r['id'],
                $r['kind'],
                mb_strimwidth(implode('; ', $r['sections']), 0, 30, '...'),
                implode(', ', $r['retrieved_pages']),
                $r['retrieval_hit'] === null ? '-' : ($r['retrieval_hit'] ? 'hit' : 'MISS'),
                strtoupper($r['verdict']),
                mb_strimwidth($r['reason'], 0, 70, '...'),
            ], $results),
        );

        $this->printSummary($results);
        $path = $this->saveRun($results);
        $this->info("Saved: {$path}");

        return self::SUCCESS;
    }

    /**
     * Apply the command's retrieval switches for this run only.
     */
    private function applyRetrievalOptions(): void
    {
        if ($this->option('top')) {
            config(['rag.retrieval.top_n' => (int) $this->option('top')]);
        }

        foreach (['hybrid', 'section-filter', 'rerank'] as $stage) {
            if ($this->option('baseline') || $this->option("no-{$stage}")) {
                config(['rag.retrieval.'.str_replace('-', '_', $stage) => false]);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadQuestions(): array
    {
        $questions = json_decode(File::get(resource_path(self::QUESTIONS_PATH)), true, flags: JSON_THROW_ON_ERROR);

        $docs = $this->option('doc');
        $ids = $this->option('id');

        return array_values(array_filter($questions, fn (array $q) => ($docs === [] || in_array($q['document'], $docs, true))
            && ($ids === [] || in_array($q['id'], $ids, true))));
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    private function evaluate(RagService $rag, LlmService $llm, array $question): array
    {
        $result = [
            'id' => $question['id'],
            'document' => $question['document'],
            'kind' => $question['kind'],
            'question' => $question['question'],
            'expected' => $question['expected'],
            'answer' => '',
            'sections' => [],
            'retrieved_pages' => [],
            'retrieval_hit' => null,
            'verdict' => 'error',
            'reason' => '',
        ];

        $document = Document::where('name', $question['document'])
            ->where('status', Document::STATUS_COMPLETED)
            ->latest('id')
            ->first();

        if (! $document) {
            $result['reason'] = 'Document not ingested';

            return $result;
        }

        try {
            ['answer' => $answer, 'chunks' => $chunks, 'sections' => $result['sections']] = $rag->answer($document, $question['question']);
        } catch (Throwable $e) {
            $result['reason'] = $e->getMessage();

            return $result;
        }

        $result['answer'] = $answer;
        $result['retrieved_pages'] = $chunks->map(fn (DocumentChunk $c) => $c->pageLabel() ?? '?')->all();

        if ($question['source_pages'] !== []) {
            $result['retrieval_hit'] = $chunks->contains(fn (DocumentChunk $c) => $c->page_start !== null
                && collect($question['source_pages'])->contains(fn (int $p) => $p >= $c->page_start && $p <= ($c->page_end ?? $c->page_start)));
        }

        try {
            [$result['verdict'], $result['reason']] = $this->judge($llm, $question, $answer);
        } catch (Throwable $e) {
            $result['reason'] = 'Judge failed: '.$e->getMessage();
        }

        return $result;
    }

    /**
     * Ask the LLM to grade the answer against the expected answer.
     *
     * @param  array<string, mixed>  $question
     * @return array{0: string, 1: string} [verdict ("pass"|"fail"), reason]
     */
    private function judge(LlmService $llm, array $question, string $answer): array
    {
        $systemPrompt = <<<'PROMPT'
            You grade answers from a document question-answering system.
            Compare the system's answer with the expected answer.

            PASS only if the answer contains the key facts and numbers of the
            expected answer, does not mix in facts that contradict it (e.g.
            figures from another region or product), and does not add claims
            presented as coming from the document that the expected answer
            doesn't support. Extra wording, page citations or harmless detail
            are fine. Small omissions of secondary detail are fine.

            If the expected answer says the information is not in the document,
            PASS only if the system says it couldn't find it, without inventing
            an answer.

            Reply with JSON only, no code fences:
            {"verdict": "pass" or "fail", "reason": "<one short sentence>"}
            PROMPT;

        $userPrompt = "Question:\n{$question['question']}\n\nExpected answer:\n{$question['expected']}\n\nSystem answer:\n{$answer}";

        $raw = $llm->generate($systemPrompt, $userPrompt);
        $json = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $raw)), true);

        $verdict = strtolower((string) ($json['verdict'] ?? ''));

        if (! in_array($verdict, ['pass', 'fail'], true)) {
            return ['error', 'Unreadable judge reply: '.mb_strimwidth($raw, 0, 100, '...')];
        }

        return [$verdict, (string) ($json['reason'] ?? '')];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function printSummary(array $results): void
    {
        $rows = collect($results)->groupBy('document')->map(function ($group, $document) {
            $retrieval = $group->whereNotNull('retrieval_hit');

            return [
                $document,
                $group->where('verdict', 'pass')->count().' / '.$group->count(),
                $retrieval->where('retrieval_hit', true)->count().' / '.$retrieval->count(),
                $group->where('verdict', 'error')->count(),
            ];
        })->values()->all();

        $this->table(['Document', 'Answers passed', 'Retrieval hits', 'Errors'], $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function saveRun(array $results): string
    {
        $directory = storage_path('app/eval');
        File::ensureDirectoryExists($directory);

        $path = $directory.'/'.now()->format('Y-m-d_His').($this->option('label') ? '_'.str($this->option('label'))->slug() : '').'.json';

        File::put($path, json_encode([
            'run_at' => now()->toIso8601String(),
            'label' => $this->option('label'),
            'chat_model' => config('services.gemini.chat_model'),
            'embedding_model' => config('services.gemini.embedding_model'),
            'retrieval' => config('rag.retrieval'),
            'results' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $path;
    }
}
