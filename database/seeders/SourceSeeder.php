<?php

namespace Database\Seeders;

use App\Models\Source;
use App\Models\Tafsir;
use Illuminate\Database\Seeder;

/**
 * The fixed V1 sources and tafsirs. Tiers are internal only.
 */
class SourceSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            ['key' => 'islamweb', 'kind' => 'fatwa', 'tier' => 1, 'name_ar' => 'إسلام ويب', 'name_en' => 'Islamweb', 'base_url' => 'https://www.islamweb.net'],
            ['key' => 'islamqa', 'kind' => 'fatwa', 'tier' => 1, 'name_ar' => 'الإسلام سؤال وجواب', 'name_en' => 'IslamQA', 'base_url' => 'https://islamqa.info'],
            ['key' => 'binbaz', 'kind' => 'fatwa', 'tier' => 2, 'name_ar' => 'موقع الشيخ ابن باز', 'name_en' => 'Sheikh Ibn Baz', 'base_url' => 'https://binbaz.org.sa'],
            ['key' => 'fawzan', 'kind' => 'fatwa', 'tier' => 3, 'name_ar' => 'مجموع فتاوى الشيخ صالح الفوزان', 'name_en' => 'Fatwas of Sheikh Salih al-Fawzan', 'base_url' => 'https://shamela.ws/book/8612'],
            ['key' => 'dorar', 'kind' => 'foundation', 'tier' => 1, 'name_ar' => 'الدرر السنية: الموسوعة الحديثية', 'name_en' => 'Dorar Hadith Encyclopedia', 'base_url' => 'https://dorar.net/hadith'],
            ['key' => 'quran', 'kind' => 'foundation', 'tier' => 1, 'name_ar' => 'القرآن الكريم', 'name_en' => 'The Quran', 'base_url' => 'https://quran.com'],
        ];

        foreach ($sources as $source) {
            Source::query()->updateOrCreate(['key' => $source['key']], $source);
        }

        $tafsirs = [
            ['key' => 'muyassar', 'name_ar' => 'التفسير الميسر', 'name_en' => 'Al-Tafsir al-Muyassar', 'source_url' => 'https://quran.com', 'is_default' => true],
            ['key' => 'ibnKathir', 'name_ar' => 'تفسير ابن كثير', 'name_en' => 'Tafsir Ibn Kathir', 'source_url' => 'https://qul.tarteel.ai', 'is_default' => false],
            ['key' => 'saadi', 'name_ar' => 'تفسير السعدي', 'name_en' => "Tafsir al-Sa'di", 'source_url' => 'https://qul.tarteel.ai', 'is_default' => false],
        ];

        foreach ($tafsirs as $tafsir) {
            Tafsir::query()->updateOrCreate(['key' => $tafsir['key']], $tafsir);
        }
    }
}
