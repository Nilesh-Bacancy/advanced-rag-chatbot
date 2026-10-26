<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Prints saved `rag:eval` runs side by side as Markdown tables, ready to
 * paste into docs/ADVANCED-RAG-REPORT.md.
 *
 * Runs are picked by label or filename fragment (e.g. "baseline",
 * "2026-10-26"); with none given, every saved run is shown. Each cell shows
 * the judge's verdict, with "(miss)" when no retrieved chunk came from a page
 * the answer is on.
 */
class RagEvalCompareCommand extends Command
{
    protected $signature = 'rag:eval-compare
        {runs?* : Labels or filename fragments of the runs to compare, in column order (default: all runs)}
        {--doc=* : Only show questions for these document names}';

    protected $description = 'Compare saved evaluation runs as Markdown tables';

    public function handle(): int
    {
        $runs = $this->loadRuns();

        if ($runs === []) {
            $this->error('No saved runs match. Run `php artisan rag:eval --label=...` first.');

            return self::FAILURE;
        }

        foreach ([...$this->summaryTable($runs), '', ...$this->questionTable($runs)] as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{name: string, results: array<int, array<string, mixed>>}>
     */
    private function loadRuns(): array
    {
        $files = collect(File::glob(storage_path('app/eval/*.json')))
            ->sort()
            ->mapWithKeys(fn (string $file) => [$file => json_decode(File::get($file), true)]);
        $wanted = $this->argument('runs');

        // A run whose label equals the selector wins over filename fragments,
        // so "baseline" doesn't also pick up "baseline-imac-h3".
        $selected = $wanted === []
            ? $files
            : collect($wanted)->flatMap(function (string $needle) use ($files) {
                $exact = $files->filter(fn (array $run) => ($run['label'] ?? null) === $needle);

                return $exact->isNotEmpty()
                    ? $exact
                    : $files->filter(fn (array $run, string $file) => str_contains(basename($file, '.json'), $needle));
            });

        $documents = $this->option('doc');

        return $selected->map(function (array $run, string $file) use ($documents) {
            $results = collect($run['results'] ?? [])
                ->when($documents !== [], fn ($c) => $c->whereIn('document', $documents))
                ->values()
                ->all();

            return ['name' => ($run['label'] ?? null) ?: basename($file, '.json'), 'results' => $results];
        })->filter(fn (array $run) => $run['results'] !== [])->values()->all();
    }

    /**
     * @param  array<int, array{name: string, results: array<int, array<string, mixed>>}>  $runs
     * @return array<int, string>
     */
    private function summaryTable(array $runs): array
    {
        $documents = collect($runs)->flatMap(fn ($run) => array_column($run['results'], 'document'))->unique()->values();

        $lines = [
            '| Document | '.implode(' | ', array_column($runs, 'name')).' |',
            '|---|'.str_repeat('---|', count($runs)),
        ];

        foreach ($documents as $document) {
            $cells = array_map(function (array $run) use ($document) {
                $group = collect($run['results'])->where('document', $document);

                return $group->isEmpty()
                    ? '-'
                    : $group->where('verdict', 'pass')->count().' / '.$group->count();
            }, $runs);

            $lines[] = "| {$document} | ".implode(' | ', $cells).' |';
        }

        return $lines;
    }

    /**
     * @param  array<int, array{name: string, results: array<int, array<string, mixed>>}>  $runs
     * @return array<int, string>
     */
    private function questionTable(array $runs): array
    {
        $ids = collect($runs)->flatMap(fn ($run) => array_column($run['results'], 'id'))->unique()->values();

        $lines = [
            '| Question | '.implode(' | ', array_column($runs, 'name')).' |',
            '|---|'.str_repeat('---|', count($runs)),
        ];

        foreach ($ids as $id) {
            $cells = array_map(function (array $run) use ($id) {
                $result = collect($run['results'])->firstWhere('id', $id);

                if ($result === null) {
                    return '-';
                }

                return strtoupper($result['verdict']).($result['retrieval_hit'] === false ? ' (miss)' : '');
            }, $runs);

            $lines[] = "| {$id} | ".implode(' | ', $cells).' |';
        }

        return $lines;
    }
}
