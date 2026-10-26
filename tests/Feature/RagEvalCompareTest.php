<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RagEvalCompareTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // Point storage at a throwaway directory so real runs are untouched.
        $this->root = storage_path('framework/testing/eval-compare');
        $this->app->useStoragePath($this->root);
        File::ensureDirectoryExists(storage_path('app/eval'));

        $this->writeRun('2026-01-01_000000_before', 'before', [
            ['id' => 'q1', 'document' => 'Doc', 'verdict' => 'pass', 'retrieval_hit' => true],
            ['id' => 'q2', 'document' => 'Doc', 'verdict' => 'fail', 'retrieval_hit' => false],
        ]);
        $this->writeRun('2026-01-02_000000_after', 'after', [
            ['id' => 'q1', 'document' => 'Doc', 'verdict' => 'pass', 'retrieval_hit' => true],
            ['id' => 'q2', 'document' => 'Doc', 'verdict' => 'pass', 'retrieval_hit' => true],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_it_prints_runs_side_by_side(): void
    {
        $this->artisan('rag:eval-compare', ['runs' => ['before', 'after']])
            ->expectsOutputToContain('| Document | before | after |')
            ->expectsOutputToContain('| Doc | 1 / 2 | 2 / 2 |')
            ->expectsOutputToContain('| q2 | FAIL (miss) | PASS |')
            ->assertSuccessful();
    }

    public function test_it_fails_when_no_run_matches(): void
    {
        $this->artisan('rag:eval-compare', ['runs' => ['nothing-like-this']])
            ->assertFailed();
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function writeRun(string $name, string $label, array $results): void
    {
        File::put(storage_path("app/eval/{$name}.json"), json_encode(['label' => $label, 'results' => $results]));
    }
}
