<?php

namespace Tests\Feature\Translation;

use App\Models\AiUsage;
use App\Models\Alert;
use App\Models\FatwaTranslation;
use App\Models\TranslationJob;
use App\Translation\TranslatorAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredTextResponse;
use RuntimeException;
use Tests\Support\MakesContent;
use Tests\TestCase;

class TitleTranslationTest extends TestCase
{
    use MakesContent;
    use RefreshDatabase;

    /** A fake Haiku that answers every item with "EN: <item>", using the given token counts. */
    private function fakeTranslator(int $inputTokens = 0, int $outputTokens = 0): void
    {
        TranslatorAgent::fake(function (string $prompt) use ($inputTokens, $outputTokens) {
            $items = json_decode($prompt, true)['items'];
            $structured = ['translations' => array_map(fn (string $item) => "EN: {$item}", $items)];

            return new StructuredTextResponse($structured, json_encode($structured), new TextUsage($inputTokens, $outputTokens), new Meta('openrouter', 'fake'));
        });
    }

    public function test_titles_translated_in_batches_and_saved_as_machine(): void
    {
        $this->fakeTranslator(1000, 500);
        $fatwas = collect(range(1, 45))->map(fn ($i) => $this->makeFatwa(['title' => "عنوان {$i}"]));

        $this->artisan('translations:titles')->assertSuccessful();

        TranslatorAgent::assertPromptedTimes(2);
        $saved = FatwaTranslation::query()->where('locale', 'en')->get();
        $this->assertCount(45, $saved);
        $this->assertSame(['machine'], $saved->pluck('kind')->unique()->all());
        $this->assertNull($saved->first()->answer, 'titles are translated up front, the body waits for the first reader');
        $this->assertSame('EN: عنوان 7', $fatwas[6]->translations()->first()->title);
        $this->assertNotNull($saved->first()->model);

        $usages = AiUsage::query()->get();
        $this->assertCount(2, $usages);
        $this->assertFalse($usages->first()->counts_toward_budget, 'titles are a one-off and do not count toward the monthly budget');
        $this->assertSame('translation', $usages->first()->purpose);
        $this->assertGreaterThan(0, (float) $usages->first()->cost_usd);

        $job = TranslationJob::query()->where(['locale' => 'en', 'scope' => 'fatwa_titles'])->first();
        $this->assertSame('done', $job->status);
        $this->assertSame(45, $job->done);
    }

    public function test_titles_are_translated_once_and_a_rerun_makes_no_call(): void
    {
        $this->fakeTranslator();
        $this->makeFatwa();

        $this->artisan('translations:titles')->assertSuccessful();
        $this->artisan('translations:titles')->assertSuccessful();

        TranslatorAgent::assertPromptedTimes(1);
    }

    public function test_source_translation_is_never_overwritten(): void
    {
        $this->fakeTranslator();
        $withSource = $this->makeFatwa(['title' => 'عنوان']);
        FatwaTranslation::query()->create(['fatwa_id' => $withSource->id, 'locale' => 'en', 'kind' => 'source', 'title' => 'Human title', 'question' => [], 'answer' => []]);
        $plain = $this->makeFatwa(['title' => 'آخر']);

        $this->artisan('translations:titles')->assertSuccessful();

        $this->assertSame('Human title', FatwaTranslation::query()->where('fatwa_id', $withSource->id)->sole()->title);
        $this->assertSame('source', FatwaTranslation::query()->where('fatwa_id', $withSource->id)->sole()->kind);
        TranslatorAgent::assertPrompted(fn ($prompt) => $prompt->contains('آخر') && ! $prompt->contains('عنوان'));
        $this->assertSame('machine', FatwaTranslation::query()->where('fatwa_id', $plain->id)->sole()->kind);
    }

    public function test_stops_at_the_cost_cap(): void
    {
        // 50M input tokens at $0.10 per million is $5: over the 4 USD cap after the first batch.
        $this->fakeTranslator(50_000_000, 0);
        config(['fatwarag.translation.pricing' => ['input_per_million' => 0.10, 'output_per_million' => 0.50]]);
        foreach (range(1, 120) as $i) {
            $this->makeFatwa();
        }

        $this->artisan('translations:titles --max-cost=4')->assertSuccessful();

        TranslatorAgent::assertPromptedTimes(1);
        $job = TranslationJob::query()->where('scope', 'fatwa_titles')->first();
        $this->assertSame('stopped', $job->status);
        $this->assertStringContainsString('cost', $job->stop_reason);
        $this->assertSame(40, $job->done);
    }

    public function test_upstream_failure_degrades(): void
    {
        TranslatorAgent::fake(fn () => throw new RuntimeException('provider down'));
        $fatwa = $this->makeFatwa();

        $this->artisan('translations:titles')->assertSuccessful();

        $this->assertSame(0, FatwaTranslation::query()->count(), 'nothing is saved from a failed call');
        $this->assertSame($fatwa->title, $fatwa->fresh()->title);
        $this->assertSame('stopped', TranslationJob::query()->first()->status);
        $this->assertTrue(Alert::query()->where('kind', 'translation')->exists());
    }

    public function test_malformed_output_saves_nothing(): void
    {
        TranslatorAgent::fake([['translations' => ['only one']]]);
        $this->makeFatwa();
        $this->makeFatwa();

        $this->artisan('translations:titles')->assertSuccessful();

        $this->assertSame(0, FatwaTranslation::query()->count());
    }

    public function test_a_second_run_does_not_start_while_one_is_active(): void
    {
        $this->fakeTranslator();
        $this->makeFatwa();
        $lock = Cache::lock('translations:titles:en', 60);
        $this->assertTrue($lock->get());

        $this->artisan('translations:titles')->assertSuccessful();

        TranslatorAgent::assertNeverPrompted();
        $lock->release();
        $this->artisan('translations:titles')->assertSuccessful();
        TranslatorAgent::assertPromptedTimes(1);
    }

    public function test_options_are_accepted(): void
    {
        $this->fakeTranslator();
        $this->makeFatwa();

        $this->artisan('translations:titles --locale=en --max-cost=1')->assertSuccessful();

        $this->assertSame(1, FatwaTranslation::query()->count());
    }
}
