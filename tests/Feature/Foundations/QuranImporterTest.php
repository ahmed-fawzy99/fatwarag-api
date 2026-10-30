<?php

namespace Tests\Feature\Foundations;

use App\Importers\Foundations\QuranImporter;
use App\Models\Ayah;
use App\Models\Surah;
use App\Support\Arabic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuranImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/quran-import-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/dl');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function chapters(): array
    {
        return [['id' => 1, 'name_arabic' => 'الفاتحة', 'name_simple' => 'Al-Fatihah', 'verses_count' => 2]];
    }

    private function fixtures(): array
    {
        $quran = ['verses' => [
            ['id' => 1, 'verse_key' => '1:1', 'text_qpc_hafs' => "بِسۡمِ ٱللَّهِ ٱلرَّحۡمَٰنِ ٱلرَّحِيمِ\u{00A0}١"],
            ['id' => 2, 'verse_key' => '1:2', 'text_qpc_hafs' => "ٱلۡحَمۡدُ لِلَّهِ رَبِّ ٱلۡعَٰلَمِينَ\u{00A0}٢"],
        ]];
        $sahih = [
            '1:1' => ['t' => 'In the name of Allāh,<sup foot_note="226402">1</sup> the Entirely Merciful.', 'f' => ['226402' => 'A proper name.']],
            '1:2' => ['t' => '[All] praise is [due] to Allāh.', 'f' => []],
        ];
        File::put($this->dir.'/dl/qc_qpc_hafs.json', json_encode($quran, JSON_UNESCAPED_UNICODE));
        File::put($this->dir.'/sahih.json', json_encode($sahih));

        return [$this->dir.'/dl/qc_qpc_hafs.json', $this->dir.'/sahih.json'];
    }

    public function test_trailing_ayah_number_is_stripped(): void
    {
        [$quran, $sahih] = $this->fixtures();
        (new QuranImporter)->import($quran, $sahih, $this->chapters());

        $text = Ayah::query()->where('surah_number', 1)->where('number', 1)->value('text');

        $this->assertStringEndsWith('ٱلرَّحِيمِ', $text);
        $this->assertDoesNotMatchRegularExpression('/[٠-٩]/u', $text);
        $this->assertStringContainsString('ِ', $text, 'harakat are kept in the stored text');
    }

    public function test_saheeh_footnotes_become_list(): void
    {
        [$quran, $sahih] = $this->fixtures();
        (new QuranImporter)->import($quran, $sahih, $this->chapters());

        $first = Ayah::query()->where('number', 1)->first();
        $second = Ayah::query()->where('number', 2)->first();

        $this->assertSame('In the name of Allāh,[1] the Entirely Merciful.', $first->translation_en);
        $this->assertSame(['A proper name.'], $first->footnotes_en);
        $this->assertSame([], $second->footnotes_en ?? []);
    }

    public function test_search_text_is_normalized(): void
    {
        [$quran, $sahih] = $this->fixtures();
        (new QuranImporter)->import($quran, $sahih, $this->chapters());

        $ayah = Ayah::query()->where('number', 1)->first();

        $this->assertSame(Arabic::normalize($ayah->text), $ayah->search_text);
        $this->assertSame('بسم الله الرحمن الرحيم', $ayah->search_text);
    }

    public function test_import_is_rerunnable_and_stores_surah_names(): void
    {
        [$quran, $sahih] = $this->fixtures();
        $importer = new QuranImporter;
        $importer->import($quran, $sahih, $this->chapters());
        $importer->import($quran, $sahih, $this->chapters());

        $this->assertSame(2, Ayah::query()->count());
        $surah = Surah::query()->find(1);
        $this->assertSame('الفاتحة', $surah->name_ar);
        $this->assertSame('Al-Fatihah', $surah->name_en);
        $this->assertSame(2, $surah->ayah_count);
    }

    public function test_chapters_are_fetched_once_and_cached(): void
    {
        Http::fake(['api.quran.com/*' => Http::response(['chapters' => $this->chapters()])]);
        $cache = $this->dir.'/chapters.json';
        $importer = new QuranImporter;

        $this->assertSame('Al-Fatihah', $importer->chapters($cache)[0]['name_simple']);
        $this->assertSame('Al-Fatihah', $importer->chapters($cache)[0]['name_simple']);

        Http::assertSentCount(1);
    }
}
