<?php

namespace Tests\Unit;

use App\Services\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmbeddingServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, int> */
    private array $pauses = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.api_key' => 'test-key', 'services.gemini.embedding_model' => 'test-model']);
    }

    public function test_batches_are_capped_by_estimated_tokens_and_paced_per_minute(): void
    {
        $this->fakeEmbeddings();

        // 90 texts of 1,000 chars ≈ 30,000 estimated tokens: more than one
        // minute's 24,000-token budget, so two batches with a pause between.
        $texts = array_map(fn (int $i) => str_pad("text {$i} ", 1000, 'x'), range(1, 90));

        $embeddings = $this->service()->embedBatch($texts);

        $this->assertCount(90, $embeddings);
        Http::assertSentCount(2);
        $this->assertCount(1, $this->pauses);
        $this->assertGreaterThan(55, $this->pauses[0]);
    }

    public function test_a_per_minute_429_waits_for_the_delay_the_api_asks_for(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['details' => [
                ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '36s'],
            ]]], 429)
            ->push(['embeddings' => [['values' => [0.1, 0.2]]]]);

        $this->service()->embedBatch(['hello']);

        $this->assertSame([38], $this->pauses);
    }

    private function fakeEmbeddings(): void
    {
        Http::fake(fn (Request $request) => Http::response([
            'embeddings' => array_map(fn () => ['values' => [0.1, 0.2]], $request['requests']),
        ]));
    }

    private function service(): EmbeddingService
    {
        $pauses = &$this->pauses;

        return new class($pauses) extends EmbeddingService
        {
            private float $clock = 0;

            public function __construct(private array &$pauses) {}

            protected function pause(int $seconds): void
            {
                $this->pauses[] = $seconds;
                $this->clock += $seconds;
            }

            protected function now(): float
            {
                return $this->clock;
            }
        };
    }
}
