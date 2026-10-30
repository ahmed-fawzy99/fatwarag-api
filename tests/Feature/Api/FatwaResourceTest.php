<?php

namespace Tests\Feature\Api;

use App\Http\Resources\FatwaListItemResource;
use App\Http\Resources\FatwaResource;
use App\Models\FatwaTranslation;
use App\Support\Arabic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesContent;
use Tests\TestCase;

class FatwaResourceTest extends TestCase
{
    use MakesContent;
    use RefreshDatabase;

    private function hadithFatwa(string $quote): array
    {
        $fatwa = $this->makeFatwa([
            'answer' => [[
                ['type' => 'text', 'text' => 'قال ﷺ: '],
                ['type' => 'hadith', 'text' => $quote, 'quoteKey' => Arabic::quoteKey($quote), 'gradingInFatwa' => null, 'gradingScope' => null, 'gradingContext' => null],
            ]],
        ]);

        return [$fatwa, $quote];
    }

    public function test_hadith_quote_key_becomes_ref_when_found_else_null(): void
    {
        [$found] = $this->hadithFatwa('إنما الأعمال بالنيات');
        $hadith = $this->makeHadith('إنما الأعمال بالنيات');
        $this->makeQuote('إنما الأعمال بالنيات', 'found', $hadith);

        $segment = FatwaResource::make($found)->resolve()['ar']['answer'][0][1];
        $this->assertSame(['collection' => 'dorar', 'number' => $hadith->id], $segment['ref']);
        $this->assertArrayNotHasKey('quoteKey', $segment);

        foreach (['not_found', 'pending'] as $status) {
            [$other] = $this->hadithFatwa("حديث {$status}");
            $this->makeQuote("حديث {$status}", $status);
            $segment = FatwaResource::make($other)->resolve()['ar']['answer'][0][1];
            $this->assertNull($segment['ref'], $status);
            $this->assertArrayNotHasKey('quoteKey', $segment);
        }

        // A quote that was never registered is treated as not found rather than crashing.
        [$unregistered] = $this->hadithFatwa('حديث غير مسجل');
        $this->assertNull(FatwaResource::make($unregistered)->resolve()['ar']['answer'][0][1]['ref']);
    }

    public function test_tier_is_never_serialized(): void
    {
        $fatwa = $this->makeFatwa();
        $json = json_encode(FatwaResource::make($fatwa)->resolve());

        $this->assertStringNotContainsString('tier', $json);
        $this->assertStringNotContainsString('search_text', $json);
        $this->assertStringNotContainsString('searchText', $json);
    }

    public function test_fatwa_matches_the_web_type(): void
    {
        $fatwa = $this->makeFatwa([
            'hijri_year' => 1424, 'hijri_month' => 6, 'hijri_day' => 20, 'date_gregorian' => '2003-08-18', 'origin' => 'نور على الدرب',
        ]);
        $topic = $this->makeTopic('taharah');
        $fatwa->topics()->attach($topic);
        $target = $this->makeFatwa();
        $this->linkFatwas($fatwa, $target);

        $data = FatwaResource::make($fatwa->fresh())->resolve();

        $this->assertSame($fatwa->public_id, $data['id']);
        $this->assertSame('islamweb', $data['sourceKey']);
        $this->assertSame($fatwa->source_fatwa_id, $data['sourceFatwaId']);
        $this->assertSame('islamweb', $data['muftiKey']);
        $this->assertSame('نور على الدرب', $data['origin']);
        $this->assertSame(['day' => 20, 'month' => 6, 'year' => 1424], $data['dateHijri']);
        $this->assertSame('2003-08-18', $data['dateGregorian']);
        $this->assertSame(['taharah'], $data['topicKeys']);
        $this->assertSame([['sourceFatwaId' => $target->source_fatwa_id, 'url' => $target->url]], $data['seeAlso']);
        $this->assertSame([], $data['referencedBy']);
        $this->assertSame(['title', 'question', 'answer', 'sourceSummary', 'url'], array_keys($data['ar']));
        $this->assertNull($data['ar']['sourceSummary']);
        $this->assertNull($data['en']);
        $this->assertNull($data['enMachine']);
    }

    public function test_missing_date_and_mufti_degrade_to_nulls(): void
    {
        $fatwa = $this->makeFatwa(['mufti_id' => null, 'hijri_year' => 1424]);

        $data = FatwaResource::make($fatwa)->resolve();

        $this->assertNull($data['dateHijri']);
        $this->assertNull($data['dateGregorian']);
        $this->assertSame('islamweb', $data['muftiKey']);
    }

    public function test_english_returns_source_and_machine_translations_separately(): void
    {
        $fatwa = $this->makeFatwa();
        $this->makeMachineTranslation($fatwa, 'Machine title', true);

        $data = FatwaResource::make($fatwa->fresh())->forLocale('en')->resolve();

        $this->assertNull($data['en']);
        $this->assertSame('Machine title', $data['enMachine']['title']);
        $this->assertSame($fatwa->url, $data['enMachine']['url']);

        FatwaTranslation::query()->where('fatwa_id', $fatwa->id)->delete();
        FatwaTranslation::query()->create([
            'fatwa_id' => $fatwa->id, 'locale' => 'en', 'kind' => 'source', 'title' => 'Source title',
            'question' => [[['type' => 'text', 'text' => 'Q']]], 'answer' => [[['type' => 'text', 'text' => 'A']]], 'url' => 'https://example.test/en',
        ]);

        $data = FatwaResource::make($fatwa->fresh())->forLocale('en')->resolve();
        $this->assertSame('Source title', $data['en']['title']);
        $this->assertSame('https://example.test/en', $data['en']['url']);
        $this->assertNull($data['enMachine']);

        // Arabic readers are not sent translations.
        $this->assertNull(FatwaResource::make($fatwa->fresh())->resolve()['en']);
    }

    public function test_title_en_carries_the_source_or_machine_title_even_without_a_body(): void
    {
        $fatwa = $this->makeFatwa();

        $data = FatwaResource::make($fatwa->fresh())->forLocale('en')->resolve();
        $this->assertNull($data['titleEn']);
        $this->assertFalse($data['titleEnTranslated']);

        $this->makeMachineTranslation($fatwa, 'Only a title', false);
        $data = FatwaResource::make($fatwa->fresh())->forLocale('en')->resolve();
        $this->assertSame('Only a title', $data['titleEn']);
        $this->assertTrue($data['titleEnTranslated']);
        $this->assertNull($data['enMachine'], 'the body is still not translated');

        FatwaTranslation::query()->where('fatwa_id', $fatwa->id)->delete();
        FatwaTranslation::query()->create(['fatwa_id' => $fatwa->id, 'locale' => 'en', 'kind' => 'source', 'title' => 'Human title', 'question' => [], 'answer' => []]);
        $data = FatwaResource::make($fatwa->fresh())->forLocale('en')->resolve();
        $this->assertSame('Human title', $data['titleEn']);
        $this->assertFalse($data['titleEnTranslated']);

        $arabic = FatwaResource::make($fatwa->fresh())->resolve();
        $this->assertNull($arabic['titleEn']);
    }

    public function test_title_only_machine_translation_is_not_a_body(): void
    {
        $fatwa = $this->makeFatwa();
        $this->makeMachineTranslation($fatwa, 'Only a title', false);

        $this->assertNull(FatwaResource::make($fatwa->fresh())->forLocale('en')->resolve()['enMachine']);
    }

    public function test_list_item_title_falls_back_to_arabic_with_flag(): void
    {
        $fatwa = $this->makeFatwa(['title' => 'عنوان عربي']);

        $item = FatwaListItemResource::make($fatwa)->forLocale('en')->resolve();
        $this->assertSame('عنوان عربي', $item['title']);
        $this->assertFalse($item['titleTranslated']);

        $this->makeMachineTranslation($fatwa, 'Machine title');
        $item = FatwaListItemResource::make($fatwa->fresh())->forLocale('en')->resolve();
        $this->assertSame('Machine title', $item['title']);
        $this->assertTrue($item['titleTranslated']);

        // Arabic readers always get the Arabic title.
        $item = FatwaListItemResource::make($fatwa->fresh())->resolve();
        $this->assertSame('عنوان عربي', $item['title']);
        $this->assertFalse($item['titleTranslated']);
    }

    public function test_source_translated_title_is_not_flagged_as_machine(): void
    {
        $fatwa = $this->makeFatwa();
        FatwaTranslation::query()->create(['fatwa_id' => $fatwa->id, 'locale' => 'en', 'kind' => 'source', 'title' => 'Human title']);

        $item = FatwaListItemResource::make($fatwa->fresh())->forLocale('en')->resolve();

        $this->assertSame('Human title', $item['title']);
        $this->assertFalse($item['titleTranslated']);
        $this->assertSame(['id', 'sourceKey', 'sourceFatwaId', 'dateHijri', 'dateGregorian', 'muftiKey', 'title', 'titleTranslated'], array_keys($item));
    }

    public function test_referenced_by_lists_backlinks(): void
    {
        $target = $this->makeFatwa();
        $a = $this->makeFatwa();
        $b = $this->makeFatwa();
        $this->linkFatwas($a, $target);
        $this->linkFatwas($b, $target);

        $data = FatwaResource::make($target->fresh())->resolve();

        $this->assertEqualsCanonicalizing([$a->public_id, $b->public_id], $data['referencedBy']);
    }

    public function test_machine_translated_hadith_segment_keeps_ref(): void
    {
        $fatwa = $this->makeFatwa();
        $hadith = $this->makeHadith('نص');
        $this->makeQuote('نص', 'found', $hadith);
        FatwaTranslation::query()->create([
            'fatwa_id' => $fatwa->id, 'locale' => 'en', 'kind' => 'machine', 'title' => 'T',
            'question' => [], 'answer' => [[['type' => 'hadith', 'text' => 'Actions are by intentions', 'quoteKey' => Arabic::quoteKey('نص'), 'gradingInFatwa' => null, 'gradingScope' => null, 'gradingContext' => null]]],
        ]);

        $segment = FatwaResource::make($fatwa->fresh())->forLocale('en')->resolve()['enMachine']['answer'][0][0];

        $this->assertSame(['collection' => 'dorar', 'number' => $hadith->id], $segment['ref']);
        $this->assertArrayNotHasKey('quoteKey', $segment);
    }
}
