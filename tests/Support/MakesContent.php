<?php

namespace Tests\Support;

use App\Models\Ayah;
use App\Models\Fatwa;
use App\Models\FatwaLink;
use App\Models\FatwaTranslation;
use App\Models\Hadith;
use App\Models\HadithGrading;
use App\Models\HadithQuote;
use App\Models\Mufti;
use App\Models\Source;
use App\Models\Surah;
use App\Models\Tafsir;
use App\Models\TafsirPassage;
use App\Models\Topic;
use App\Support\Arabic;
use Illuminate\Support\Str;

/**
 * Builds content rows for tests, so none of them depends on collected data.
 */
trait MakesContent
{
    protected function makeSource(string $key = 'islamweb', int $tier = 1): Source
    {
        return Source::query()->firstOrCreate(['key' => $key], [
            'kind' => 'fatwa',
            'tier' => $tier,
            'name_ar' => "مصدر {$key}",
            'name_en' => "Source {$key}",
            'base_url' => "https://{$key}.example",
        ]);
    }

    protected function makeMufti(string $key = 'islamweb'): Mufti
    {
        return Mufti::query()->firstOrCreate(['key' => $key], ['kind' => 'institution', 'name_ar' => "مفتي {$key}", 'name_en' => "Mufti {$key}"]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeFatwa(array $attributes = [], string $sourceKey = 'islamweb'): Fatwa
    {
        $source = $this->makeSource($sourceKey);
        $id = $attributes['source_fatwa_id'] ?? (string) random_int(1000, 9_999_999);

        return Fatwa::query()->create($attributes + [
            'public_id' => "{$sourceKey}-{$id}",
            'source_id' => $source->id,
            'source_fatwa_id' => $id,
            'url' => "https://{$sourceKey}.example/fatwa/{$id}",
            'mufti_id' => $this->makeMufti($sourceKey)->id,
            'title' => 'عنوان الفتوى '.Str::random(6),
            'question' => [[['type' => 'text', 'text' => 'ما حكم هذا؟']]],
            'answer' => [[['type' => 'text', 'text' => 'الحمد لله، هذا جائز.']]],
            'search_text' => 'ما حكم هذا الحمد لله هذا جائز',
        ]);
    }

    protected function makeTopic(string $key, ?Topic $parent = null, int $position = 0): Topic
    {
        return Topic::query()->create([
            'key' => $key,
            'parent_id' => $parent?->id,
            'name_ar' => "موضوع {$key}",
            'name_en' => "Topic {$key}",
            'position' => $position,
        ]);
    }

    protected function makeQuote(string $quote, string $status = 'pending', ?Hadith $hadith = null): HadithQuote
    {
        return HadithQuote::query()->create([
            'quote_key' => Arabic::quoteKey($quote),
            'quote' => $quote,
            'status' => $status,
            'hadith_id' => $hadith?->id,
        ]);
    }

    protected function makeHadith(string $text = 'إنما الأعمال بالنيات'): Hadith
    {
        return Hadith::query()->create(['text' => $text, 'search_text' => $text]);
    }

    protected function makeGrading(Hadith $hadith, string $level, string $phrase, int $position = 0): HadithGrading
    {
        return HadithGrading::query()->create([
            'hadith_id' => $hadith->id,
            'dorar_hash' => Str::random(8),
            'scholar' => 'الألباني',
            'scholar_en' => 'Al-Albani',
            'phrase' => $phrase,
            'level' => $level,
            'position' => $position,
        ]);
    }

    protected function makeMachineTranslation(Fatwa $fatwa, string $title = 'English title', bool $withBody = false, string $locale = 'en'): FatwaTranslation
    {
        return FatwaTranslation::query()->create([
            'fatwa_id' => $fatwa->id,
            'locale' => $locale,
            'kind' => 'machine',
            'title' => $title,
            'question' => $withBody ? [[['type' => 'text', 'text' => 'What is the ruling?']]] : null,
            'answer' => $withBody ? [[['type' => 'text', 'text' => 'Praise be to God, it is permissible.']]] : null,
            'model' => 'test-model',
        ]);
    }

    protected function linkFatwas(Fatwa $from, Fatwa $to): FatwaLink
    {
        return FatwaLink::query()->create([
            'fatwa_id' => $from->id,
            'target_source_fatwa_id' => $to->source_fatwa_id,
            'target_fatwa_id' => $to->id,
        ]);
    }

    protected function makeSurah(int $number = 2, int $ayat = 3): Surah
    {
        $surah = Surah::query()->create(['number' => $number, 'name_ar' => 'البقرة', 'name_en' => 'Al-Baqarah', 'ayah_count' => $ayat]);

        for ($i = 1; $i <= $ayat; $i++) {
            Ayah::query()->create([
                'surah_number' => $number,
                'number' => $i,
                'text' => "آية {$i}",
                'search_text' => "اية {$i}",
                'translation_en' => "Verse {$i}",
                'footnotes_en' => $i === 1 ? ['A footnote'] : [],
            ]);
        }

        return $surah;
    }

    protected function makeTafsir(string $key, string $nameEn = 'Tafsir'): Tafsir
    {
        return Tafsir::query()->firstOrCreate(['key' => $key], ['name_ar' => "تفسير {$key}", 'name_en' => "{$nameEn} {$key}", 'is_default' => $key === 'muyassar']);
    }

    protected function makePassage(Tafsir $tafsir, int $surah, int $from, int $to, string $text): TafsirPassage
    {
        return TafsirPassage::query()->create(['tafsir_id' => $tafsir->id, 'surah_number' => $surah, 'ayah_from' => $from, 'ayah_to' => $to, 'text' => $text]);
    }
}
