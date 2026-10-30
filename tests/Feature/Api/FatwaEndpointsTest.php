<?php

namespace Tests\Feature\Api;

use App\Models\FatwaTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesContent;
use Tests\TestCase;

class FatwaEndpointsTest extends TestCase
{
    use MakesContent;
    use RefreshDatabase;

    public function test_fatwas_by_ids_keeps_order_and_skips_missing(): void
    {
        $a = $this->makeFatwa();
        $b = $this->makeFatwa(['source_fatwa_id' => '222'], 'islamqa');
        $c = $this->makeFatwa();

        $response = $this->getJson("/api/v1/fatwas?ids={$c->public_id},missing-1,{$a->public_id},{$b->public_id}");

        $response->assertOk();
        $this->assertSame([$c->public_id, $a->public_id, $b->public_id], array_column($response->json(), 'id'));
        $response->assertJsonPath('0.ar.title', $c->title);
    }

    public function test_fatwas_by_ids_requires_ids_and_caps_the_list(): void
    {
        $this->getJson('/api/v1/fatwas')->assertStatus(422)->assertJsonPath('error.code', 'validation');

        $ids = implode(',', array_map(fn ($i) => "x-{$i}", range(1, 80)));
        $this->getJson("/api/v1/fatwas?ids={$ids}")->assertOk()->assertExactJson([]);
    }

    public function test_show_returns_the_fatwa_with_english_for_english_locale(): void
    {
        $fatwa = $this->makeFatwa();
        $this->makeMachineTranslation($fatwa, 'English title', true);

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}")->assertOk()
            ->assertJsonPath('id', $fatwa->public_id)
            ->assertJsonPath('enMachine', null);

        $this->getJson("/api/v1/fatwas/{$fatwa->public_id}?locale=en")->assertOk()
            ->assertJsonPath('enMachine.title', 'English title');
    }

    public function test_unknown_fatwa_404_shape(): void
    {
        $this->getJson('/api/v1/fatwas/islamweb-nope')
            ->assertNotFound()
            ->assertJsonStructure(['error' => ['code', 'message']])
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_related_prefers_shared_topics_and_excludes_given(): void
    {
        $wudu = $this->makeTopic('wudu');
        $salah = $this->makeTopic('salah');
        $zakat = $this->makeTopic('zakat');

        $subject = $this->makeFatwa();
        $subject->topics()->attach([$wudu->id, $salah->id]);
        $both = $this->makeFatwa();
        $both->topics()->attach([$wudu->id, $salah->id]);
        $one = $this->makeFatwa();
        $one->topics()->attach([$wudu->id]);
        $none = $this->makeFatwa();
        $none->topics()->attach([$zakat->id]);
        $excluded = $this->makeFatwa();
        $excluded->topics()->attach([$wudu->id, $salah->id]);

        $response = $this->getJson("/api/v1/fatwas/{$subject->public_id}/related?exclude={$excluded->public_id}");
        $response->assertOk();
        $ids = array_column($response->json(), 'id');
        $this->assertSame([$both->public_id, $one->public_id], $ids);
        $this->assertSame(['id', 'sourceKey', 'sourceFatwaId', 'dateHijri', 'dateGregorian', 'muftiKey', 'title', 'titleTranslated'], array_keys($response->json('0')));

        $byTopics = $this->getJson("/api/v1/related?topicKeys=wudu,salah&exclude={$both->public_id},{$excluded->public_id}");
        $this->assertSame([$subject->public_id, $one->public_id], array_column($byTopics->json(), 'id'));
    }

    public function test_related_returns_at_most_five_and_nothing_without_topics(): void
    {
        $topic = $this->makeTopic('wudu');
        foreach (range(1, 8) as $i) {
            $this->makeFatwa()->topics()->attach($topic);
        }
        $lonely = $this->makeFatwa();

        $this->assertCount(5, $this->getJson('/api/v1/related?topicKeys=wudu')->json());
        $this->getJson("/api/v1/fatwas/{$lonely->public_id}/related")->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/related')->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/fatwas/unknown-1/related')->assertNotFound();
    }

    public function test_related_titles_follow_locale(): void
    {
        $topic = $this->makeTopic('wudu');
        $other = $this->makeFatwa();
        $other->topics()->attach($topic);
        $this->makeMachineTranslation($other, 'English title');

        $this->getJson('/api/v1/related?topicKeys=wudu&locale=en')
            ->assertJsonPath('0.title', 'English title')
            ->assertJsonPath('0.titleTranslated', true);
        $this->getJson('/api/v1/related?topicKeys=wudu')->assertJsonPath('0.titleTranslated', false);
    }

    public function test_hadith_sitemap_lists_only_found_hadiths(): void
    {
        config(['fatwarag.content.sitemap_per_page' => 2]);
        $found = collect(range(1, 3))->map(function ($i) {
            $hadith = $this->makeHadith("نص {$i}");
            $this->makeQuote("نص {$i}", 'found', $hadith);

            return $hadith;
        });
        $this->makeQuote('غير موجود', 'not_found');
        $this->makeQuote('معلق', 'pending');
        $this->makeHadith('بلا اقتباس');

        $first = $this->getJson('/api/v1/sitemap/hadiths')->assertOk();
        $first->assertJsonPath('meta', ['page' => 1, 'perPage' => 2, 'total' => 3, 'lastPage' => 2]);
        $this->assertSame(['id', 'updatedAt'], array_keys($first->json('data.0')));
        $second = $this->getJson('/api/v1/sitemap/hadiths?page=2')->assertOk();
        $ids = array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'));
        $this->assertEqualsCanonicalizing($found->pluck('id')->all(), $ids);
        $this->assertCount(0, $this->getJson('/api/v1/sitemap/hadiths?page=9')->json('data'));
    }

    public function test_sitemap_pages(): void
    {
        config(['fatwarag.content.sitemap_per_page' => 2]);
        $fatwas = collect(range(1, 5))->map(fn () => $this->makeFatwa());
        $this->makeMachineTranslation($fatwas[1], 'English', true);
        $this->makeMachineTranslation($fatwas[2], 'Title only');

        $first = $this->getJson('/api/v1/sitemap/fatwas');
        $first->assertOk()->assertJsonPath('meta', ['page' => 1, 'perPage' => 2, 'total' => 5, 'lastPage' => 3]);
        $this->assertCount(2, $first->json('data'));
        $this->assertSame(['id', 'updatedAt', 'hasEnglish'], array_keys($first->json('data.0')));

        $this->assertCount(1, $this->getJson('/api/v1/sitemap/fatwas?page=3')->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/sitemap/fatwas?page=9')->json('data'));

        $all = collect([1, 2, 3])->flatMap(fn ($p) => $this->getJson("/api/v1/sitemap/fatwas?page={$p}")->json('data'))->keyBy('id');
        $this->assertTrue($all[$fatwas[1]->public_id]['hasEnglish']);
        $this->assertFalse($all[$fatwas[2]->public_id]['hasEnglish'], 'a machine title alone is not an English page');
        $this->assertFalse($all[$fatwas[0]->public_id]['hasEnglish']);
        $this->assertTrue(FatwaTranslation::query()->exists());
    }
}
