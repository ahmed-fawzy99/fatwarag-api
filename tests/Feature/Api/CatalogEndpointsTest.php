<?php

namespace Tests\Feature\Api;

use App\Models\GlossaryTerm;
use App\Models\Mufti;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesContent;
use Tests\TestCase;

class CatalogEndpointsTest extends TestCase
{
    use MakesContent;
    use RefreshDatabase;

    public function test_topic_tree_has_counts_including_descendants(): void
    {
        $parent = $this->makeTopic('worship', null, 1);
        $child = $this->makeTopic('taharah', $parent, 1);
        $other = $this->makeTopic('prayer', $parent, 2);
        $empty = $this->makeTopic('zakat', null, 2);

        $inBoth = $this->makeFatwa();
        $inBoth->topics()->attach([$child->id, $other->id]);
        $this->makeFatwa()->topics()->attach($child);
        $this->makeFatwa()->topics()->attach($parent);

        $tree = collect($this->getJson('/api/v1/topics')->assertOk()->json())->keyBy('key');

        $this->assertSame(['worship', 'zakat'], $tree->keys()->all());
        $this->assertSame(3, $tree['worship']['fatwaCount'], 'a fatwa in two children counts once for the parent');
        $this->assertSame(0, $tree['zakat']['fatwaCount']);
        $children = collect($tree['worship']['children'])->keyBy('key');
        $this->assertSame(['taharah', 'prayer'], $children->keys()->all());
        $this->assertSame(2, $children['taharah']['fatwaCount']);
        $this->assertSame(1, $children['prayer']['fatwaCount']);
        $this->assertSame([], $children['prayer']['children']);
        $this->assertSame(['key', 'labelAr', 'labelEn', 'children', 'fatwaCount'], array_keys($tree['worship']));
        $this->assertSame('موضوع worship', $tree['worship']['labelAr']);
        $this->assertSame($empty->name_en, $tree['zakat']['labelEn']);
    }

    public function test_topic_fatwas_paginated(): void
    {
        config(['fatwarag.content.topic_per_page' => 2]);
        $parent = $this->makeTopic('worship');
        $child = $this->makeTopic('taharah', $parent);
        foreach (range(1, 3) as $i) {
            $this->makeFatwa()->topics()->attach($child);
        }
        $this->makeFatwa()->topics()->attach($parent);
        $this->makeFatwa();

        $page1 = $this->getJson('/api/v1/topics/worship/fatwas');
        $page1->assertOk()->assertJsonPath('meta', ['page' => 1, 'perPage' => 2, 'total' => 4, 'lastPage' => 2]);
        $this->assertCount(2, $page1->json('data'));
        $this->assertSame(['id', 'sourceKey', 'sourceFatwaId', 'dateHijri', 'dateGregorian', 'muftiKey', 'title', 'titleTranslated'], array_keys($page1->json('data.0')));

        $page2 = $this->getJson('/api/v1/topics/worship/fatwas?page=2');
        $this->assertCount(2, $page2->json('data'));
        $this->assertSame([], array_intersect(array_column($page1->json('data'), 'id'), array_column($page2->json('data'), 'id')));
        $this->assertCount(1, $this->getJson('/api/v1/topics/taharah/fatwas?page=2')->json('data'));
    }

    public function test_topic_fatwas_use_the_locale_title_and_unknown_topic_is_404(): void
    {
        $topic = $this->makeTopic('worship');
        $fatwa = $this->makeFatwa();
        $fatwa->topics()->attach($topic);
        $this->makeMachineTranslation($fatwa, 'English title');

        $this->getJson('/api/v1/topics/worship/fatwas?locale=en')->assertJsonPath('data.0.title', 'English title');
        $this->getJson('/api/v1/topics/nothing/fatwas')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_sources_exclude_foundations_and_tier(): void
    {
        $this->makeSource('islamweb', 1);
        $this->makeSource('fawzan', 3);
        $dorar = $this->makeSource('dorar');
        $dorar->update(['kind' => 'foundation']);

        $response = $this->getJson('/api/v1/sources')->assertOk();

        $keys = array_column($response->json(), 'key');
        $this->assertEqualsCanonicalizing(['islamweb', 'fawzan'], $keys);
        $this->assertSame(['key', 'nameAr', 'nameEn', 'homeUrl'], array_keys($response->json('0')));
        $this->assertStringNotContainsString('tier', $response->getContent());
    }

    public function test_mufti_returns_the_card(): void
    {
        Mufti::query()->create(['key' => 'binbaz', 'kind' => 'person', 'name_ar' => 'ابن باز', 'name_en' => 'Ibn Baz', 'bio_ar' => 'نبذة', 'source_url' => 'https://binbaz.org.sa/bio']);
        $this->makeMufti('islamweb');

        $this->getJson('/api/v1/muftis/binbaz')->assertOk()->assertExactJson([
            'key' => 'binbaz', 'nameAr' => 'ابن باز', 'nameEn' => 'Ibn Baz', 'kind' => 'scholar',
            'bioAr' => 'نبذة', 'bioEn' => null, 'bioSourceUrl' => 'https://binbaz.org.sa/bio',
        ]);
        $this->getJson('/api/v1/muftis/islamweb')->assertJsonPath('kind', 'institution')->assertJsonPath('bioSourceUrl', null);
        $this->getJson('/api/v1/muftis/nobody')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_mufti_fatwas_paginated_like_topic_fatwas(): void
    {
        config(['fatwarag.content.topic_per_page' => 2]);
        $mine = collect(range(1, 3))->map(fn () => $this->makeFatwa(['mufti_id' => $this->makeMufti('binbaz')->id], 'binbaz'));
        $this->makeFatwa(['mufti_id' => $this->makeMufti('fawzan')->id], 'fawzan');
        $this->makeMachineTranslation($mine[0], 'English title');

        $page1 = $this->getJson('/api/v1/muftis/binbaz/fatwas?locale=en')->assertOk();
        $page1->assertJsonPath('meta', ['page' => 1, 'perPage' => 2, 'total' => 3, 'lastPage' => 2]);
        $this->assertSame(['id', 'sourceKey', 'sourceFatwaId', 'dateHijri', 'dateGregorian', 'muftiKey', 'title', 'titleTranslated'], array_keys($page1->json('data.0')));
        $this->assertSame(['binbaz'], array_unique(array_column($page1->json('data'), 'muftiKey')));
        $this->assertCount(1, $this->getJson('/api/v1/muftis/binbaz/fatwas?page=2')->json('data'));
        $this->assertContains('English title', array_merge(array_column($this->getJson('/api/v1/muftis/binbaz/fatwas?locale=en&page=1')->json('data'), 'title'), array_column($this->getJson('/api/v1/muftis/binbaz/fatwas?locale=en&page=2')->json('data'), 'title')));
        $this->getJson('/api/v1/muftis/nobody/fatwas')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_glossary_shape(): void
    {
        $text = fn (string $body) => ['paragraphs' => [$body], 'sourceName' => 'IslamQA', 'sourceUrl' => 'https://islamqa.info/ar/answers/1'];
        GlossaryTerm::query()->create(['key' => 'daif', 'label_ar' => 'ضعيف', 'label_en' => 'Daif', 'level' => 'daif', 'entry_ar' => ['meaning' => $text('م'), 'acting' => null], 'position' => 2]);
        GlossaryTerm::query()->create(['key' => 'sahih', 'label_ar' => 'صحيح', 'label_en' => 'Sahih', 'level' => 'sahih', 'entry_ar' => ['meaning' => $text('م'), 'acting' => $text('ع')], 'entry_en' => ['meaning' => $text('m'), 'acting' => null], 'position' => 1]);
        Setting::put('glossary_intro', ['ar' => $text('مقدمة'), 'en' => null]);

        $response = $this->getJson('/api/v1/glossary')->assertOk();

        $this->assertSame(['intro', 'terms'], array_keys($response->json()));
        $response->assertJsonPath('intro.ar.paragraphs', ['مقدمة'])->assertJsonPath('intro.en', null);
        $this->assertSame(['sahih', 'daif'], array_column($response->json('terms'), 'term'));
        $this->assertSame(['term', 'labelAr', 'labelEn', 'level', 'ar', 'en'], array_keys($response->json('terms.0')));
        $response->assertJsonPath('terms.0.ar.acting.paragraphs', ['ع'])->assertJsonPath('terms.0.en.meaning.paragraphs', ['m'])->assertJsonPath('terms.1.en', null)->assertJsonPath('terms.1.ar.acting', null);
    }

    public function test_glossary_without_an_intro_still_has_the_shape(): void
    {
        $this->getJson('/api/v1/glossary')->assertOk()->assertExactJson(['intro' => ['ar' => null, 'en' => null], 'terms' => []]);
    }
}
