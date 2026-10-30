<?php

namespace Tests\Feature\Api;

use App\Models\Hadith;
use App\Models\HadithExplanation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesContent;
use Tests\TestCase;

class FoundationEndpointsTest extends TestCase
{
    use MakesContent;
    use RefreshDatabase;

    public function test_surah_returns_all_ayat_with_translation(): void
    {
        $this->makeSurah(2, 3);
        $this->makeSurah(1, 7);

        $response = $this->getJson('/api/v1/quran/surahs/2');

        $response->assertOk()
            ->assertJsonPath('number', 2)
            ->assertJsonPath('nameAr', 'البقرة')
            ->assertJsonPath('nameEn', 'Al-Baqarah')
            ->assertJsonPath('partial', false);
        $this->assertSame([1, 2, 3], array_column($response->json('ayat'), 'ayah'));
        $this->assertSame(['ayah', 'ar', 'en', 'enFootnotes'], array_keys($response->json('ayat.0')));
        $response->assertJsonPath('ayat.0.ar', 'آية 1')->assertJsonPath('ayat.0.en', 'Verse 1')->assertJsonPath('ayat.0.enFootnotes', ['A footnote']);
        $response->assertJsonPath('ayat.1.enFootnotes', []);
    }

    public function test_unknown_surah_is_404_in_the_error_shape(): void
    {
        $this->getJson('/api/v1/quran/surahs/115')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->getJson('/api/v1/quran/surahs/abc')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_tafsir_returns_passage_covering_ayah_for_each_tafsir(): void
    {
        $this->makeSurah(2, 10);
        $muyassar = $this->makeTafsir('muyassar');
        $kathir = $this->makeTafsir('ibnKathir');
        $this->makeTafsir('saadi');
        $this->makePassage($muyassar, 2, 5, 5, 'muyassar five');
        $this->makePassage($muyassar, 2, 6, 6, 'muyassar six');
        $this->makePassage($kathir, 2, 3, 7, 'kathir three to seven');

        $response = $this->getJson('/api/v1/quran/tafsir/2/5');

        $response->assertOk()
            ->assertJsonPath('surah', 2)
            ->assertJsonPath('ayah', 5)
            ->assertJsonPath('texts.muyassar', 'muyassar five')
            ->assertJsonPath('texts.ibnKathir', 'kathir three to seven')
            ->assertJsonPath('texts.saadi', null);
        $this->assertSame(['muyassar', 'ibnKathir', 'saadi'], array_keys($response->json('texts')));
    }

    public function test_tafsir_for_a_verse_that_does_not_exist_is_404(): void
    {
        $this->makeSurah(2, 3);

        $this->getJson('/api/v1/quran/tafsir/2/4')->assertNotFound();
        $this->getJson('/api/v1/quran/tafsir/9/1')->assertNotFound();
    }

    public function test_hadith_grades_strongest_first(): void
    {
        $hadith = $this->makeHadith('نص الحديث');
        $this->makeGrading($hadith, 'daif', 'إسناده ضعيف', 0);
        $this->makeGrading($hadith, 'mawdu', 'موضوع', 1);
        $this->makeGrading($hadith, 'sahih', 'صحيح', 2);
        $this->makeGrading($hadith, 'hasan', 'حسن', 3);

        $response = $this->getJson("/api/v1/hadiths/{$hadith->id}");

        $response->assertOk()
            ->assertJsonPath('collection', 'dorar')
            ->assertJsonPath('number', $hadith->id)
            ->assertJsonPath('displayNumber', $hadith->id)
            ->assertJsonPath('section', null)
            ->assertJsonPath('sectionAr', null)
            ->assertJsonPath('sectionEn', null)
            ->assertJsonPath('ar', 'نص الحديث')
            ->assertJsonPath('en', null)
            ->assertJsonPath('explanation', null);
        $this->assertSame(['sahih', 'hasan', 'daif', 'mawdu'], array_column($response->json('grades'), 'level'));
        $this->assertSame(
            ['scholarAr', 'scholarEn', 'level', 'labelAr', 'labelEn', 'explainedAr', 'bookAr', 'page', 'sourceUrl'],
            array_keys($response->json('grades.0')),
        );
        $response->assertJsonPath('grades.0.labelAr', 'صحيح')->assertJsonPath('grades.0.scholarEn', 'Al-Albani');
    }

    public function test_grading_without_english_name_falls_back_to_arabic(): void
    {
        $hadith = $this->makeHadith();
        $grading = $this->makeGrading($hadith, 'sahih', 'صحيح');
        $grading->update(['scholar_en' => null, 'book' => 'صحيح الجامع', 'reference' => '123']);

        $this->getJson("/api/v1/hadiths/{$hadith->id}")
            ->assertJsonPath('grades.0.scholarEn', 'الألباني')
            ->assertJsonPath('grades.0.bookAr', 'صحيح الجامع')
            ->assertJsonPath('grades.0.page', '123')
            ->assertJsonPath('grades.0.sourceUrl', 'https://dorar.net/h/'.$grading->dorar_hash);
    }

    public function test_hadith_with_an_exact_explanation_returns_it(): void
    {
        $explanation = HadithExplanation::query()->create(['dorar_sharh_id' => 's1', 'text' => "الفقرة الأولى\n\nالفقرة الثانية", 'source_url' => 'https://dorar.net/hadith/sharh/1']);
        $hadith = $this->makeHadith();
        $hadith->update(['hadith_explanation_id' => $explanation->id]);

        $this->getJson("/api/v1/hadiths/{$hadith->id}")
            ->assertOk()
            ->assertJsonPath('explanation.paragraphs', ['الفقرة الأولى', 'الفقرة الثانية'])
            ->assertJsonPath('explanation.sourceUrl', 'https://dorar.net/hadith/sharh/1')
            ->assertJsonStructure(['explanation' => ['paragraphs', 'sourceName', 'sourceUrl']]);
    }

    public function test_similar_sharh_not_returned(): void
    {
        // A commentary exists in the library, but only a hadith it was exactly matched to may show it.
        HadithExplanation::query()->create(['dorar_sharh_id' => 'similar', 'text' => 'شرح حديث مشابه', 'source_url' => 'https://dorar.net/hadith/sharh/2']);
        $hadith = $this->makeHadith();

        $this->getJson("/api/v1/hadiths/{$hadith->id}")->assertOk()->assertJsonPath('explanation', null);
    }

    public function test_translated_hadith_text_is_returned_and_unknown_is_404(): void
    {
        $hadith = $this->makeHadith();
        Hadith::query()->whereKey($hadith->id)->update(['translation_en' => 'Actions are judged by intentions.']);

        $this->getJson("/api/v1/hadiths/{$hadith->id}?locale=en")->assertOk()->assertJsonPath('en', 'Actions are judged by intentions.');
        $this->getJson('/api/v1/hadiths/999999')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->getJson('/api/v1/hadiths/abc')->assertNotFound();
    }
}
