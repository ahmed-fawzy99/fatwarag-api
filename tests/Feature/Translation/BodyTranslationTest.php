<?php

namespace Tests\Feature\Translation;

use App\Jobs\TranslateFatwaBody;
use App\Models\AiUsage;
use App\Models\Alert;
use App\Models\FatwaTranslation;
use App\Models\Setting;
use App\Support\Arabic;
use App\Translation\BodyTranslator;
use App\Translation\TranslationTimedOut;
use App\Translation\TranslatorAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredTextResponse;
use RuntimeException;
use Tests\Support\MakesContent;
use Tests\TestCase;

class BodyTranslationTest extends TestCase
{
    use MakesContent;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Visitor limits live in the ask cache store (Redis); tests must not touch it.
        config(['fatwarag.ask.cache_store' => 'array']);
    }

    private function fakeTranslator(): void
    {
        TranslatorAgent::fake(function (string $prompt) {
            $items = json_decode($prompt, true)['items'];
            $structured = ['translations' => array_map(fn (string $item) => "EN: {$item}", $items)];

            return new StructuredTextResponse($structured, json_encode($structured), new TextUsage(2000, 1000), new Meta('openrouter', 'fake'));
        });
    }

    private function richFatwa(): array
    {
        $fatwa = $this->makeFatwa([
            'title' => 'عنوان',
            'question' => [[['type' => 'text', 'text' => 'سؤال']]],
            'answer' => [[
                ['type' => 'text', 'text' => 'قال تعالى: '],
                ['type' => 'quran', 'text' => 'الحمد لله رب العالمين', 'surah' => 1, 'ayah' => 2, 'ayahEnd' => null],
                ['type' => 'text', 'text' => ' وقال ﷺ: '],
                ['type' => 'hadith', 'text' => 'إنما الأعمال بالنيات', 'quoteKey' => Arabic::quoteKey('إنما الأعمال بالنيات'), 'gradingInFatwa' => null, 'gradingScope' => null, 'gradingContext' => null],
            ], [
                ['type' => 'scholar', 'text' => 'قال ابن القيم', 'scholar' => 'ابن القيم', 'book' => 'زاد المعاد'],
                ['type' => 'fatwaRef', 'text' => 'انظر الفتوى', 'fatwaId' => 'islamweb-1'],
            ]],
            'source_summary' => null,
        ]);

        return [$fatwa, $this->makeHadith('إنما الأعمال بالنيات')];
    }

    public function test_body_translated_once_then_reused(): void
    {
        $this->fakeTranslator();
        [$fatwa, $hadith] = $this->richFatwa();
        $this->makeQuote('إنما الأعمال بالنيات', 'found', $hadith);

        $first = $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation?locale=en");

        $first->assertOk();
        TranslatorAgent::assertPromptedTimes(1);
        $first->assertJsonPath('title', 'EN: عنوان')->assertJsonPath('url', $fatwa->url)->assertJsonPath('sourceSummary', null);
        $first->assertJsonPath('question.0.0.text', 'EN: سؤال');

        $segments = $first->json('answer.0');
        $this->assertSame('EN: قال تعالى: ', $segments[0]['text']);
        $this->assertSame('الحمد لله رب العالمين', $segments[1]['text'], 'Quran segments keep the Arabic');
        $this->assertSame(['surah' => 1, 'ayah' => 2, 'ayahEnd' => null], ['surah' => $segments[1]['surah'], 'ayah' => $segments[1]['ayah'], 'ayahEnd' => $segments[1]['ayahEnd']]);
        $this->assertSame('hadith', $segments[3]['type']);
        $this->assertSame('EN: إنما الأعمال بالنيات', $segments[3]['text']);
        $this->assertSame(['collection' => 'dorar', 'number' => $hadith->id], $segments[3]['ref'], 'a translated hadith keeps its link');
        $this->assertArrayNotHasKey('quoteKey', $segments[3]);
        $this->assertSame('EN: قال ابن القيم', $first->json('answer.1.0.text'));
        $this->assertSame('ابن القيم', $first->json('answer.1.0.scholar'));
        $this->assertSame('islamweb-1', $first->json('answer.1.1.fatwaId'));

        $second = $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation?locale=en");

        $second->assertOk()->assertExactJson($first->json());
        TranslatorAgent::assertPromptedTimes(1);
        $this->assertSame(1, FatwaTranslation::query()->where('fatwa_id', $fatwa->id)->count());
        $this->assertSame('machine', FatwaTranslation::query()->where('fatwa_id', $fatwa->id)->sole()->kind);

        // The Arabic text is untouched.
        $this->assertSame('عنوان', $fatwa->fresh()->title);
        $this->assertSame('قال تعالى: ', $fatwa->fresh()->answer[0][0]['text']);
    }

    public function test_body_translation_counts_toward_the_budget(): void
    {
        $this->fakeTranslator();
        [$fatwa] = $this->richFatwa();

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")->assertOk();

        $usage = AiUsage::query()->sole();
        $this->assertSame('translation', $usage->purpose);
        $this->assertTrue($usage->counts_toward_budget);
    }

    public function test_existing_machine_title_is_kept_when_the_body_is_added(): void
    {
        $this->fakeTranslator();
        $fatwa = $this->makeFatwa(['title' => 'عنوان']);
        $this->makeMachineTranslation($fatwa, 'Title made up front');

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")->assertOk()->assertJsonPath('title', 'Title made up front');

        TranslatorAgent::assertNotPrompted(fn ($prompt) => $prompt->contains('عنوان'));
        $this->assertSame(1, FatwaTranslation::query()->count());
        $this->assertTrue(FatwaTranslation::query()->sole()->hasBody());
    }

    public function test_source_translation_is_returned_without_an_ai_call(): void
    {
        $this->fakeTranslator();
        $fatwa = $this->makeFatwa();
        FatwaTranslation::query()->create([
            'fatwa_id' => $fatwa->id, 'locale' => 'en', 'kind' => 'source', 'title' => 'Human title',
            'question' => [[['type' => 'text', 'text' => 'Q']]], 'answer' => [[['type' => 'text', 'text' => 'A']]], 'url' => 'https://example.test/en',
        ]);

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")->assertOk()->assertJsonPath('title', 'Human title')->assertJsonPath('url', 'https://example.test/en');

        TranslatorAgent::assertNeverPrompted();
    }

    public function test_upstream_failure_degrades(): void
    {
        TranslatorAgent::fake(fn () => throw new RuntimeException('provider down'));
        [$fatwa] = $this->richFatwa();

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'upstream_failed');

        $this->assertSame(0, FatwaTranslation::query()->count());
        $this->assertTrue(Alert::query()->where('kind', 'translation')->exists());
        // The Arabic fatwa still reads fine, and the next reader can try again.
        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}")->assertOk()->assertJsonPath('enMachine', null);
    }

    public function test_the_real_cause_is_logged_without_secrets(): void
    {
        config(['fatwarag.openrouter.key' => 'sk-secret-key']);
        Log::spy();
        TranslatorAgent::fake(fn () => throw ProviderConnectionException::forProvider('openrouter', 0, new ConnectionException('cURL error 28: timed out, Authorization: Bearer sk-secret-key')));
        [$fatwa] = $this->richFatwa();

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")->assertStatus(504);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) {
            $json = json_encode($context);

            return $message === 'Translation call failed' && str_contains($json, 'cURL error 28') && ! str_contains($json, 'sk-secret-key') && $context['timedOut'] === true;
        })->atLeast()->once();
    }

    public function test_malformed_output_saves_nothing(): void
    {
        TranslatorAgent::fake([['translations' => ['one']]]);
        [$fatwa] = $this->richFatwa();

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")->assertStatus(502);

        $this->assertSame(0, FatwaTranslation::query()->count());
    }

    public function test_provider_timeout_returns_504(): void
    {
        // The SDK reports a slow provider as "could not connect", with the cURL timeout underneath.
        TranslatorAgent::fake(fn () => throw ProviderConnectionException::forProvider('openrouter', 0, new ConnectionException('cURL error 28: Operation timed out')));
        [$fatwa] = $this->richFatwa();

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")->assertStatus(504)->assertJsonPath('error.code', 'timeout');

        $this->assertSame(0, FatwaTranslation::query()->count());
    }

    public function test_a_translation_that_outlasts_the_reader_finishes_in_the_background(): void
    {
        $this->fakeTranslator();
        [$fatwa] = $this->richFatwa();
        // No time left for the reader to wait: the reader gets a 504 and the work carries on after the response.
        config(['fatwarag.translation.body_timeout' => 0]);

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")->assertStatus(504)->assertJsonPath('error.code', 'timeout');
        $this->assertTrue(app(BodyTranslator::class)->lockFor($fatwa, 'en')->get(), 'the background job released the lock when it finished');

        $this->assertSame('machine', FatwaTranslation::query()->where('fatwa_id', $fatwa->id)->sole()->kind, 'the background job saved it');
        TranslatorAgent::assertPromptedTimes(1);
        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")->assertOk()->assertJsonPath('title', 'EN: عنوان');
        TranslatorAgent::assertPromptedTimes(1);
    }

    public function test_bot_user_agent_never_triggers_translation(): void
    {
        $this->fakeTranslator();
        [$fatwa] = $this->richFatwa();

        foreach (['Googlebot/2.1 (+http://www.google.com/bot.html)', 'Mozilla/5.0 (compatible; bingbot/2.0)', 'facebookexternalhit/1.1'] as $agent) {
            $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation", ['User-Agent' => $agent])
                ->assertNotFound()
                ->assertJsonPath('error.code', 'not_found');
        }

        TranslatorAgent::assertNeverPrompted();
        $this->assertSame(0, FatwaTranslation::query()->count());

        // A browser on the same fatwa does trigger it.
        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation", ['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/130.0'])->assertOk();
        TranslatorAgent::assertPrompted(fn () => true);
    }

    public function test_a_phone_with_bot_in_its_name_is_not_a_crawler(): void
    {
        $this->fakeTranslator();
        [$fatwa] = $this->richFatwa();

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation", ['User-Agent' => 'Mozilla/5.0 (Linux; Android 9; CUBOT_X18_Plus) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36'])->assertOk();
    }

    public function test_crawlers_still_get_a_translation_that_already_exists(): void
    {
        $this->fakeTranslator();
        $fatwa = $this->makeFatwa();
        $this->makeMachineTranslation($fatwa, 'Title', true);

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation", ['User-Agent' => 'Googlebot/2.1'])->assertOk()->assertJsonPath('title', 'Title');

        TranslatorAgent::assertNeverPrompted();
    }

    public function test_paused_when_budget_exhausted(): void
    {
        $this->fakeTranslator();
        [$fatwa] = $this->richFatwa();
        AiUsage::query()->create(['purpose' => 'summary', 'model' => 'm', 'cost_usd' => 100.5, 'counts_toward_budget' => true]);

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'budget_exhausted');

        TranslatorAgent::assertNeverPrompted();
        $this->assertSame(0, FatwaTranslation::query()->count());
    }

    public function test_a_stored_translation_is_served_even_when_the_budget_is_gone(): void
    {
        $fatwa = $this->makeFatwa();
        $this->makeMachineTranslation($fatwa, 'Title', true);
        AiUsage::query()->create(['purpose' => 'summary', 'model' => 'm', 'cost_usd' => 500, 'counts_toward_budget' => true]);

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")->assertOk();
    }

    public function test_unknown_fatwa_and_unsupported_locale(): void
    {
        $this->getJson('/api/v1/fatwas/islamweb-nope/translation')->assertNotFound()->assertJsonPath('error.code', 'not_found');

        $fatwa = $this->makeFatwa();
        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation?locale=fr")->assertStatus(422)->assertJsonPath('error.code', 'validation');
    }

    public function test_a_long_fatwa_is_translated_in_several_calls_and_a_retry_resumes(): void
    {
        $paragraphs = array_map(fn ($i) => [['type' => 'text', 'text' => str_repeat('كلمة ', 300)." {$i}"]], range(1, 6));
        $fatwa = $this->makeFatwa(['answer' => $paragraphs]);
        $calls = 0;
        $seen = [];
        $respond = function (string $prompt) use (&$calls, &$seen) {
            $calls++;
            $seen[] = $prompt;
            $items = json_decode($prompt, true)['items'];
            $structured = ['translations' => array_map(fn (string $item) => "EN: {$item}", $items)];

            return new StructuredTextResponse($structured, json_encode($structured), new TextUsage(10, 10), new Meta('openrouter', 'fake'));
        };

        // The second call fails: nothing is saved, but the first chunk is remembered.
        TranslatorAgent::fake(function (string $prompt) use (&$calls, $respond) {
            return $calls === 1 ? throw new RuntimeException('hiccup') : $respond($prompt);
        });
        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")->assertStatus(502);
        $this->assertSame(0, FatwaTranslation::query()->count());
        $callsBeforeRetry = $calls;

        TranslatorAgent::fake($respond);
        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")->assertOk();

        $this->assertSame(1, $callsBeforeRetry);
        $this->assertGreaterThan(2, $calls, 'a long fatwa needs several calls');
        $this->assertSame($seen, array_values(array_unique($seen)), 'the chunk done before the failure is not translated again');
        $this->assertCount(6, FatwaTranslation::query()->sole()->answer);
    }

    public function test_the_background_job_keeps_the_lock_so_nobody_pays_twice(): void
    {
        [$fatwa] = $this->richFatwa();
        $heldDuringJob = null;
        TranslatorAgent::fake(function (string $prompt) use ($fatwa, &$heldDuringJob) {
            // Inside the AI call of the background job: the fatwa's lock must still be held.
            $heldDuringJob = ! app(BodyTranslator::class)->lockFor($fatwa, 'en')->get();
            $items = json_decode($prompt, true)['items'];
            $structured = ['translations' => array_map(fn (string $item) => "EN: {$item}", $items)];

            return new StructuredTextResponse($structured, json_encode($structured), new TextUsage(1, 1), new Meta('openrouter', 'fake'));
        });
        config(['fatwarag.translation.body_timeout' => 0]);

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")->assertStatus(504);

        $this->assertTrue($heldDuringJob);
        $this->assertSame(1, FatwaTranslation::query()->count());
    }

    public function test_a_reader_who_finds_the_translation_in_progress_gets_an_immediate_504(): void
    {
        $this->fakeTranslator();
        [$fatwa] = $this->richFatwa();
        $lock = app(BodyTranslator::class)->lockFor($fatwa, 'en');
        $this->assertTrue($lock->get());

        $started = microtime(true);
        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")->assertStatus(504)->assertJsonPath('error.code', 'timeout');

        $this->assertLessThan(2, microtime(true) - $started, 'no waiting on the lock');
        TranslatorAgent::assertNeverPrompted();
        $this->assertTrue(app(BodyTranslator::class)->lockFor($fatwa, 'en')->get() === false, 'the other request still holds its lock');
        $this->assertSame(0, FatwaTranslation::query()->count());
    }

    public function test_translations_that_call_the_ai_are_rate_limited_per_visitor_but_stored_ones_are_free(): void
    {
        $this->fakeTranslator();
        Setting::put('hourly_translations_per_visitor', 2);
        $fatwas = collect(range(1, 4))->map(fn () => $this->makeFatwa());

        $this->getJson("/api/v1/fatwas/{$fatwas[0]->public_id}/translation")->assertOk();
        $this->getJson("/api/v1/fatwas/{$fatwas[1]->public_id}/translation")->assertOk();
        $this->getJson("/api/v1/fatwas/{$fatwas[2]->public_id}/translation")->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');

        // Already translated: free, and the limit is not touched.
        $this->getJson("/api/v1/fatwas/{$fatwas[0]->public_id}/translation")->assertOk();
        $this->assertSame(0, FatwaTranslation::query()->where('fatwa_id', $fatwas[2]->id)->count());
        TranslatorAgent::assertPromptedTimes(2);

        // Another visitor (forwarded by the BFF) has their own allowance, and the lock was released after the 429.
        config(['fatwarag.bff_key' => 'bff-secret']);
        $this->getJson("/api/v1/fatwas/{$fatwas[2]->public_id}/translation", ['X-BFF-Key' => 'bff-secret', 'X-Client-IP' => '203.0.113.9'])->assertOk();
    }

    public function test_the_budget_is_checked_between_chunks(): void
    {
        $paragraphs = array_map(fn ($i) => [['type' => 'text', 'text' => str_repeat('كلمة ', 300)." {$i}"]], range(1, 6));
        $fatwa = $this->makeFatwa(['answer' => $paragraphs]);
        TranslatorAgent::fake(function (string $prompt) {
            $items = json_decode($prompt, true)['items'];
            $structured = ['translations' => array_map(fn (string $item) => "EN: {$item}", $items)];
            // The first call spends the rest of the month's budget.
            AiUsage::query()->create(['purpose' => 'summary', 'model' => 'm', 'cost_usd' => 500, 'counts_toward_budget' => true]);

            return new StructuredTextResponse($structured, json_encode($structured), new TextUsage(1, 1), new Meta('openrouter', 'fake'));
        });

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")->assertStatus(503)->assertJsonPath('error.code', 'budget_exhausted');

        TranslatorAgent::assertPromptedTimes(1);
        $this->assertSame(0, FatwaTranslation::query()->count());
    }

    public function test_a_source_translation_with_only_a_title_is_404_not_an_empty_body(): void
    {
        $this->fakeTranslator();
        $fatwa = $this->makeFatwa();
        FatwaTranslation::query()->create(['fatwa_id' => $fatwa->id, 'locale' => 'en', 'kind' => 'source', 'title' => 'Human title']);

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")->assertNotFound()->assertJsonPath('error.code', 'not_found');

        TranslatorAgent::assertNeverPrompted();
    }

    public function test_a_broken_lock_store_degrades_to_502(): void
    {
        $this->fakeTranslator();
        [$fatwa] = $this->richFatwa();
        config(['cache.default' => 'no-such-store']);

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")->assertStatus(502)->assertJsonPath('error.code', 'upstream_failed');

        TranslatorAgent::assertNeverPrompted();
    }

    public function test_secrets_are_redacted_from_alerts_and_the_cost_of_an_abandoned_call_is_recorded(): void
    {
        config(['fatwarag.openrouter.key' => 'sk-or-secret-123456']);
        TranslatorAgent::fake(fn () => throw new RuntimeException('401 for key sk-or-secret-123456'));
        [$fatwa] = $this->richFatwa();

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")->assertStatus(502);

        $alert = Alert::query()->where('kind', 'translation')->sole();
        $this->assertStringNotContainsString('sk-or-secret-123456', json_encode($alert->context).$alert->message);
        $this->assertStringContainsString('[redacted]', json_encode($alert->context));

        // A call abandoned at its timeout may still be billed: its input is counted.
        TranslatorAgent::fake(fn () => throw ProviderConnectionException::forProvider('openrouter', 0, new ConnectionException('cURL error 28: timed out')));
        $other = $this->makeFatwa();
        $this->getJson("/api/v1/fatwas/{$other->public_id}/translation")->assertStatus(504);

        $this->assertTrue(AiUsage::query()->where('purpose', 'translation')->where('input_tokens', '>', 0)->exists());
    }

    public function test_the_lock_outlives_the_longest_continuation(): void
    {
        $longest = (int) config('fatwarag.translation.body_timeout') + 2 * (int) config('fatwarag.translation.call_timeout') + TranslateFatwaBody::WAIT;

        $this->assertGreaterThanOrEqual($longest, BodyTranslator::LOCK_SECONDS);
    }

    public function test_no_new_call_starts_when_the_lock_is_about_to_expire(): void
    {
        $this->fakeTranslator();
        [$fatwa] = $this->richFatwa();

        // Less than one call timeout (90 s) of lock left: another reader could take over mid-call and pay again.
        $this->expectException(TranslationTimedOut::class);

        try {
            app(BodyTranslator::class)->translate($fatwa, 'en', 100, microtime(true) + 30);
        } finally {
            TranslatorAgent::assertNeverPrompted();
            $this->assertSame(0, FatwaTranslation::query()->count());
        }
    }

    public function test_a_broken_limiter_store_degrades_to_502_and_releases_the_lock(): void
    {
        $this->fakeTranslator();
        [$fatwa] = $this->richFatwa();
        config(['fatwarag.ask.cache_store' => 'no-such-store']);

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}/translation")->assertStatus(502)->assertJsonPath('error.code', 'upstream_failed');

        TranslatorAgent::assertNeverPrompted();
        $this->assertTrue(Alert::query()->where('fingerprint', 'translation-limiter-failed')->exists());
        $this->assertTrue(app(BodyTranslator::class)->lockFor($fatwa, 'en')->get(), 'the lock was released');
    }
}
