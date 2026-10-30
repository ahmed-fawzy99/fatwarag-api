<?php

namespace Tests\Feature\Api;

use App\Content\ContentCache;
use App\Models\Fatwa;
use App\Models\FatwaTranslation;
use App\Support\Arabic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MakesContent;
use Tests\TestCase;

class ContentCacheTest extends TestCase
{
    use MakesContent;
    use RefreshDatabase;

    private function queryCount(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function hadithFatwa(string $quote): Fatwa
    {
        return $this->makeFatwa(['answer' => [[['type' => 'hadith', 'text' => $quote, 'quoteKey' => Arabic::quoteKey($quote), 'gradingInFatwa' => null, 'gradingScope' => null, 'gradingContext' => null]]]]);
    }

    public function test_second_request_served_from_cache(): void
    {
        $fatwa = $this->makeFatwa();
        $topic = $this->makeTopic('wudu');
        $fatwa->topics()->attach($topic);
        $this->makeSurah(2, 2);
        $hadith = $this->makeHadith();
        $this->makeSource('islamweb');
        $this->makeMufti('islamweb');

        $paths = [
            "/api/v1/fatwas/{$fatwa->public_id}",
            "/api/v1/fatwas?ids={$fatwa->public_id}",
            "/api/v1/fatwas/{$fatwa->public_id}/related",
            '/api/v1/related?topicKeys=wudu',
            '/api/v1/topics',
            '/api/v1/topics/wudu/fatwas',
            '/api/v1/quran/surahs/2',
            '/api/v1/quran/tafsir/2/1',
            "/api/v1/hadiths/{$hadith->id}",
            '/api/v1/sources',
            '/api/v1/muftis/islamweb',
            '/api/v1/glossary',
            '/api/v1/sitemap/fatwas',
        ];

        foreach ($paths as $path) {
            $first = $this->getJson($path)->assertOk()->json();
            $queries = $this->queryCount(fn () => $this->assertSame($first, $this->getJson($path)->json()));

            $this->assertSame(0, $queries, "{$path} should be answered from the cache");
        }
    }

    public function test_errors_are_not_cached(): void
    {
        $this->getJson('/api/v1/fatwas/islamweb-late')->assertNotFound();
        $fatwa = $this->makeFatwa(['source_fatwa_id' => 'late']);

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}")->assertOk();
        $this->makeSurah(3, 1);
        $this->getJson('/api/v1/quran/surahs/3')->assertOk();
    }

    public function test_locale_is_part_of_the_cache_key(): void
    {
        $fatwa = $this->makeFatwa();
        $this->makeMachineTranslation($fatwa, 'English title', true);

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}")->assertJsonPath('enMachine', null);
        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}?locale=en")->assertJsonPath('enMachine.title', 'English title');
        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}")->assertJsonPath('enMachine', null);
    }

    public function test_saving_translation_clears_fatwa_cache(): void
    {
        $fatwa = $this->makeFatwa(['title' => 'عنوان']);
        $topic = $this->makeTopic('wudu');
        $fatwa->topics()->attach($topic);

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}?locale=en")->assertJsonPath('enMachine', null);
        $this->getJson('/api/v1/topics/wudu/fatwas?locale=en')->assertJsonPath('data.0.title', 'عنوان');

        $translation = $this->makeMachineTranslation($fatwa, 'English title');
        $this->getJson('/api/v1/topics/wudu/fatwas?locale=en')->assertJsonPath('data.0.title', 'English title');

        $translation->update(['question' => [[['type' => 'text', 'text' => 'Q']]], 'answer' => [[['type' => 'text', 'text' => 'A']]]]);
        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}?locale=en")->assertJsonPath('enMachine.answer.0.0.text', 'A');
    }

    public function test_updating_a_fatwa_clears_its_cache_but_not_others(): void
    {
        $changed = $this->makeFatwa(['title' => 'قديم']);
        $other = $this->makeFatwa();
        $this->getJson("/api/v1/fatwas/{$changed->public_id}")->assertOk();
        $this->getJson("/api/v1/fatwas/{$other->public_id}")->assertOk();

        $changed->update(['title' => 'جديد']);

        $this->getJson("/api/v1/fatwas/{$changed->public_id}")->assertJsonPath('ar.title', 'جديد');
        $this->assertSame(0, $this->queryCount(fn () => $this->getJson("/api/v1/fatwas/{$other->public_id}")));
    }

    public function test_saving_the_same_data_again_keeps_the_cache(): void
    {
        $fatwa = $this->makeFatwa();
        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}")->assertOk();

        $fatwa->fresh()->save();

        $this->assertSame(0, $this->queryCount(fn () => $this->getJson("/api/v1/fatwas/{$fatwa->public_id}")));
    }

    public function test_new_link_clears_the_referenced_fatwa(): void
    {
        $target = $this->makeFatwa();
        $source = $this->makeFatwa();
        $this->getJson("/api/v1/fatwas/{$target->public_id}")->assertJsonPath('referencedBy', []);

        $this->linkFatwas($source, $target);

        $this->getJson("/api/v1/fatwas/{$target->public_id}")->assertJsonPath('referencedBy', [$source->public_id]);
    }

    public function test_hadith_quote_found_clears_fatwas_quoting_it(): void
    {
        $quoting = $this->hadithFatwa('إنما الأعمال بالنيات');
        $unrelated = $this->hadithFatwa('حديث آخر');
        $quote = $this->makeQuote('إنما الأعمال بالنيات', 'pending');
        $this->makeQuote('حديث آخر', 'pending');
        $hadith = $this->makeHadith('إنما الأعمال بالنيات');

        $this->getJson("/api/v1/fatwas/{$quoting->public_id}")->assertJsonPath('ar.answer.0.0.ref', null);
        $this->getJson("/api/v1/fatwas/{$unrelated->public_id}")->assertJsonPath('ar.answer.0.0.ref', null);

        $quote->update(['status' => 'found', 'hadith_id' => $hadith->id]);

        $this->getJson("/api/v1/fatwas/{$quoting->public_id}")->assertJsonPath('ar.answer.0.0.ref', ['collection' => 'dorar', 'number' => $hadith->id]);
        $this->assertSame(0, $this->queryCount(fn () => $this->getJson("/api/v1/fatwas/{$unrelated->public_id}")), 'a fatwa that does not quote it stays cached');
    }

    public function test_bulk_topic_assignment_is_picked_up_after_the_derived_caches_are_cleared(): void
    {
        $fatwa = $this->makeFatwa();
        $topic = $this->makeTopic('wudu');
        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}")->assertJsonPath('topicKeys', []);

        // How topics:build files fatwas: raw rows, no model events.
        DB::table('fatwa_topic')->insert(['fatwa_id' => $fatwa->id, 'topic_id' => $topic->id]);
        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}")->assertJsonPath('topicKeys', []);

        $this->artisan('content:cache-clear')->assertSuccessful();

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}")->assertJsonPath('topicKeys', ['wudu']);
    }

    public function test_a_hadith_found_later_joins_the_hadith_sitemap(): void
    {
        $hadith = $this->makeHadith('نص');
        $quote = $this->makeQuote('نص', 'pending');
        $this->getJson('/api/v1/sitemap/hadiths')->assertJsonPath('meta.total', 0);

        $quote->update(['status' => 'found', 'hadith_id' => $hadith->id]);

        $this->getJson('/api/v1/sitemap/hadiths')->assertJsonPath('meta.total', 1);
    }

    public function test_hadith_changes_clear_the_hadith(): void
    {
        $hadith = $this->makeHadith();
        $this->getJson("/api/v1/hadiths/{$hadith->id}")->assertJsonPath('grades', []);

        $this->makeGrading($hadith, 'sahih', 'صحيح');

        $this->getJson("/api/v1/hadiths/{$hadith->id}")->assertJsonCount(1, 'grades');
    }

    public function test_a_broken_cache_degrades_to_computing(): void
    {
        config(['fatwarag.content.cache_store' => 'no-such-store']);
        $fatwa = $this->makeFatwa();

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}")->assertOk()->assertJsonPath('id', $fatwa->public_id);
        $this->getJson('/api/v1/topics')->assertOk();
        FatwaTranslation::query()->count();
        ContentCache::forgetLists();
    }
}
