<?php

namespace Tests\Feature\Foundations;

use App\Importers\Foundations\TafsirImporter;
use App\Models\Surah;
use App\Models\Tafsir;
use App\Models\TafsirPassage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TafsirImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tafsir-import-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function write(string $path, array $data): void
    {
        File::ensureDirectoryExists(dirname("{$this->dir}/{$path}"));
        File::put("{$this->dir}/{$path}", json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function surahs(): void
    {
        foreach ([1 => 7, 2 => 6] as $number => $count) {
            Surah::query()->create(['number' => $number, 'name_ar' => "س{$number}", 'name_en' => "S{$number}", 'ayah_count' => $count]);
        }
    }

    public function test_runs_of_identical_text_become_one_passage_with_range(): void
    {
        $passages = TafsirImporter::runs([1 => 'a', 2 => 'a', 3 => 'a', 4 => 'b', 5 => 'a']);

        $this->assertSame([
            ['from' => 1, 'to' => 3, 'text' => 'a'],
            ['from' => 4, 'to' => 4, 'text' => 'b'],
            ['from' => 5, 'to' => 5, 'text' => 'a'],
        ], $passages);
    }

    public function test_quran_com_grouped_ayahs_use_verses_range(): void
    {
        // Quran.com lists a grouped passage once, at its first verse; the omitted verses up to the next entry belong to it.
        $entries = [
            ['verse_key' => '2:1', 'text' => 'one'],
            ['verse_key' => '2:2', 'text' => 'two-to-three'],
            ['verse_key' => '2:4', 'text' => 'four-to-end'],
        ];

        $this->assertSame([
            ['from' => 1, 'to' => 1, 'text' => 'one'],
            ['from' => 2, 'to' => 3, 'text' => 'two-to-three'],
            ['from' => 4, 'to' => 6, 'text' => 'four-to-end'],
        ], TafsirImporter::quranComPassages($entries, 6));
    }

    public function test_ibn_kathir_2_1_boundary_fix_moves_prefix_to_1_7(): void
    {
        $basmala = '[بِسْمِ اللَّهِ الرَّحْمَنِ الرَّحِيمِ]';
        $this->surahs();
        $this->write('ar-tafsir-ibn-kathir/1.json', collect(range(1, 7))->map(fn ($a) => ['surah' => 1, 'ayah' => $a, 'text' => "fatiha {$a}"])->all());
        $this->write('ar-tafsir-ibn-kathir/2.json', collect(range(1, 6))->map(fn ($a) => [
            'surah' => 2, 'ayah' => $a,
            'text' => $a === 1 ? "end of fatiha 7\n\n{$basmala} [[note]]\n\nتَفْسِيرُ سُورَةِ الْبَقَرَةِ\n\nbaqara intro" : "baqara {$a}",
        ])->all());

        (new TafsirImporter)->import($this->dir, ['ibnKathir']);

        $tafsir = Tafsir::query()->where('key', 'ibnKathir')->first();
        $seven = TafsirPassage::query()->where('tafsir_id', $tafsir->id)->where('surah_number', 1)->where('ayah_from', 7)->first();
        $baqaraOne = TafsirPassage::query()->where('tafsir_id', $tafsir->id)->where('surah_number', 2)->where('ayah_from', 1)->first();

        $this->assertStringContainsString('end of fatiha 7', $seven->text);
        $this->assertStringStartsWith('fatiha 7', $seven->text);
        $this->assertStringNotContainsString('end of fatiha 7', $baqaraOne->text);
        $this->assertStringStartsWith($basmala, $baqaraOne->text);
        $this->assertStringContainsString('baqara intro', $baqaraOne->text);
    }

    public function test_saadi_mid_sentence_line_breaks_are_joined(): void
    {
        $text = "السكوت عن\nالتعرُّض لمعناها.\nوهذه قاعدة.\n\nفقرة جديدة\n{2} آية";

        $this->assertSame("السكوت عن التعرُّض لمعناها.\nوهذه قاعدة.\n\nفقرة جديدة\n{2} آية", TafsirImporter::joinWrappedLines($text));
    }

    public function test_import_is_rerunnable_and_maps_all_three_tafsirs(): void
    {
        $this->surahs();
        $this->write('qc/16_1.json', ['tafsirs' => [['verse_key' => '1:1', 'text' => 'm1'], ['verse_key' => '1:3', 'text' => 'm3']]]);
        $this->write('ar-tafsir-as-saadi/1.json', collect(range(1, 7))->map(fn ($a) => ['surah' => 1, 'ayah' => $a, 'text' => $a < 3 ? "s\nx" : "t{$a}"])->all());

        $importer = new TafsirImporter;
        $importer->import($this->dir);
        $importer->import($this->dir);

        $this->assertSame(['muyassar', 'ibnKathir', 'saadi'], Tafsir::query()->orderBy('id')->pluck('key')->all());
        $muyassar = Tafsir::query()->where('key', 'muyassar')->first();
        $this->assertSame(2, $muyassar->passages()->count());
        $this->assertSame(7, $muyassar->passages()->where('ayah_from', 3)->value('ayah_to'));
        $saadi = Tafsir::query()->where('key', 'saadi')->first();
        $this->assertSame('s x', $saadi->passages()->where('ayah_from', 1)->first()->text);
        $this->assertSame(2, $saadi->passages()->where('ayah_from', 1)->first()->ayah_to);
    }

    public function test_empty_source_keeps_existing_passages(): void
    {
        $this->surahs();
        $this->write('qc/16_1.json', ['tafsirs' => [['verse_key' => '1:1', 'text' => 'm1']]]);
        (new TafsirImporter)->import($this->dir, ['muyassar']);

        File::deleteDirectory("{$this->dir}/qc");
        $counts = (new TafsirImporter)->import($this->dir, ['muyassar']);

        $this->assertSame(0, $counts['muyassar']);
        $this->assertSame(1, TafsirPassage::query()->count());
    }
}
