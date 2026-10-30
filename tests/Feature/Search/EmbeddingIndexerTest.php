<?php

namespace Tests\Feature\Search;

use App\Models\AiUsage;
use App\Models\Alert;
use App\Models\FatwaChunk;
use App\Search\EmbeddingIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use RuntimeException;
use Tests\Support\MakesSearchableFatwas;
use Tests\TestCase;

class EmbeddingIndexerTest extends TestCase
{
    use MakesSearchableFatwas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    public function test_indexer_embeds_only_unembedded_chunks(): void
    {
        Embeddings::fake();

        $embedded = $this->searchFatwa('فتوى مفهرسة');
        $existing = $this->embedFatwa($embedded, $this->axisVector(5));
        $fresh = $this->searchFatwa('فتوى جديدة', 'نص الجواب الجديد');

        $result = app(EmbeddingIndexer::class)->run();

        $this->assertSame(2, $result['embedded']); // title+question chunk and one answer chunk of the new fatwa
        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->contains('نص الجواب الجديد'));
        Embeddings::assertNotGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->contains($existing->content));

        $this->assertSame(0, FatwaChunk::query()->whereNull('embedding')->count());
        $this->assertSame(2, $fresh->chunks()->count());
        $this->assertEquals($this->axisVector(5)[5], $existing->fresh()->embedding->toArray()[5], '', 0.001);
    }

    public function test_indexer_records_cost_outside_the_budget(): void
    {
        Embeddings::fake(fn (EmbeddingsPrompt $prompt) => new EmbeddingsResponse(
            array_map(fn () => Embeddings::fakeEmbedding(1024), $prompt->inputs),
            new Usage(1_000_000),
            new Meta('openrouter', 'voyageai/voyage-4-large'),
        ));

        $this->searchFatwa('فتوى');

        app(EmbeddingIndexer::class)->run();

        $usage = AiUsage::query()->sole();
        $this->assertSame(EmbeddingIndexer::PURPOSE, $usage->purpose);
        $this->assertFalse($usage->counts_toward_budget);
        $this->assertEqualsWithDelta(0.12, (float) $usage->cost_usd, 0.0001);
    }

    public function test_indexer_stops_at_cost_cap_and_raises_alert(): void
    {
        Embeddings::fake();

        AiUsage::query()->create([
            'purpose' => EmbeddingIndexer::PURPOSE, 'model' => 'voyageai/voyage-4-large',
            'input_tokens' => 1, 'output_tokens' => 0, 'cost_usd' => 5, 'counts_toward_budget' => false,
        ]);
        $this->searchFatwa('فتوى');

        $result = app(EmbeddingIndexer::class)->run(maxCost: 5.0);

        $this->assertTrue($result['stoppedAtCap']);
        $this->assertSame(0, $result['embedded']);
        Embeddings::assertNothingGenerated();
        $this->assertGreaterThan(0, FatwaChunk::query()->whereNull('embedding')->count());
        $this->assertTrue(Alert::query()->where('fingerprint', 'embeddings:cost-cap')->exists());
    }

    public function test_upstream_failure_raises_alert_and_keeps_chunks_for_next_run(): void
    {
        Embeddings::fake(fn () => throw new RuntimeException('provider down'));

        $this->searchFatwa('فتوى');

        $result = app(EmbeddingIndexer::class)->run();

        $this->assertSame(0, $result['embedded']);
        $this->assertGreaterThan(0, FatwaChunk::query()->whereNull('embedding')->count());
        $this->assertTrue(Alert::query()->where('fingerprint', 'embeddings:upstream')->exists());
    }

    public function test_changed_fatwa_is_rechunked(): void
    {
        Embeddings::fake();

        $fatwa = $this->searchFatwa('فتوى', 'الجواب القديم');
        app(EmbeddingIndexer::class)->run();

        $this->travel(1)->minutes();
        $fatwa->update(['answer' => [[['type' => 'text', 'text' => 'الجواب المعدل']]]]);

        app(EmbeddingIndexer::class)->run();

        $contents = $fatwa->chunks()->pluck('content')->implode(' ');
        $this->assertStringContainsString('الجواب المعدل', $contents);
        $this->assertStringNotContainsString('الجواب القديم', $contents);
        $this->assertSame(0, FatwaChunk::query()->whereNull('embedding')->count());
    }

    public function test_change_that_keeps_the_text_keeps_embeddings(): void
    {
        Embeddings::fake();

        $fatwa = $this->searchFatwa('فتوى', 'الجواب نفسه');
        app(EmbeddingIndexer::class)->run();
        $before = $fatwa->chunks()->pluck('id')->all();

        $this->travel(1)->minutes();
        $fatwa->update(['url' => 'https://islamweb.example/moved']);

        $result = app(EmbeddingIndexer::class)->run();

        $this->assertSame(0, $result['embedded']);
        $this->assertSame($before, $fatwa->chunks()->pluck('id')->all());
        $this->assertSame(0, app(EmbeddingIndexer::class)->chunkPending());
    }

    public function test_chunks_embedded_without_input_type_are_re_embedded_as_documents(): void
    {
        Embeddings::fake();

        $fatwa = $this->searchFatwa('فتوى قديمة');
        $old = $this->embedFatwa($fatwa, $this->axisVector(5));
        $old->forceFill(['embedding_input_type' => null])->save();

        $result = app(EmbeddingIndexer::class)->run();

        $this->assertGreaterThanOrEqual(1, $result['embedded']);
        $this->assertSame('document', $old->fresh()->embedding_input_type);
        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => ($prompt->providerOptions['input_type'] ?? null) === 'document' && $prompt->contains($old->content));
    }

    public function test_command_runs_the_indexer(): void
    {
        Embeddings::fake();
        $this->searchFatwa('فتوى');

        $this->artisan('fatwas:embed', ['--limit' => 10])->assertSuccessful();

        $this->assertSame(0, FatwaChunk::query()->whereNull('embedding')->count());
    }
}
