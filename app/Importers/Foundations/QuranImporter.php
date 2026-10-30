<?php

namespace App\Importers\Foundations;

use App\Models\Ayah;
use App\Models\Surah;
use App\Support\Arabic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Imports the Quran (KFGQPC Hafs text from Quran.com) with the Saheeh International translation and its footnotes.
 * Safe to re-run: ayahs are upserted by surah and number.
 */
class QuranImporter
{
    private const CHAPTERS_URL = 'https://api.quran.com/api/v4/chapters';

    /**
     * Surah names, fetched once from Quran.com and cached in the raw store.
     *
     * @return array<int, array<string, mixed>>
     */
    public function chapters(string $cachePath): array
    {
        if (is_file($cachePath)) {
            return json_decode(File::get($cachePath), true)['chapters'];
        }

        $response = Http::timeout(30)->retry(3, 1000)->get(self::CHAPTERS_URL, ['language' => 'en']);

        if (! $response->successful() || empty($response->json('chapters'))) {
            throw new RuntimeException('Could not fetch the surah list from Quran.com.');
        }

        File::ensureDirectoryExists(dirname($cachePath));
        File::put($cachePath, json_encode(['chapters' => $response->json('chapters')], JSON_UNESCAPED_UNICODE));

        return $response->json('chapters');
    }

    /**
     * Removes the verse number the Quran.com text ends with (e.g. "... ١٢٣"), keeping everything else.
     */
    public static function cleanAyahText(string $text): string
    {
        return trim(preg_replace('/[\s\x{00A0}]*[٠-٩]+\s*$/u', '', $text));
    }

    /**
     * Turns `<sup foot_note="ID">n</sup>` into the marker `[n]` and collects the footnote texts in marker order.
     *
     * @param  array<string, string>  $footnotes  footnote id => text
     * @return array{0: string, 1: list<string>}
     */
    public static function parseTranslation(string $html, array $footnotes): array
    {
        $list = [];
        $text = preg_replace_callback('/<sup\s+foot_note="(\d+)">\s*(\d+)\s*<\/sup>/', function (array $m) use ($footnotes, &$list) {
            $list[] = trim($footnotes[$m[1]] ?? '');

            return '['.$m[2].']';
        }, $html);

        return [trim(strip_tags($text)), $list];
    }

    /**
     * @param  array<int, array<string, mixed>>  $chapters
     * @return array{surahs: int, ayahs: int}
     */
    public function import(string $quranPath, string $sahihPath, array $chapters): array
    {
        $verses = json_decode(File::get($quranPath), true)['verses'] ?? throw new RuntimeException('The Quran file has no verses.');
        $sahih = json_decode(File::get($sahihPath), true);

        DB::transaction(function () use ($chapters, $verses, $sahih) {
            foreach ($chapters as $chapter) {
                Surah::query()->updateOrCreate(['number' => $chapter['id']], [
                    'name_ar' => $chapter['name_arabic'],
                    'name_en' => $chapter['name_simple'],
                    'ayah_count' => $chapter['verses_count'],
                ]);
            }

            foreach ($verses as $verse) {
                [$surah, $number] = array_map('intval', explode(':', $verse['verse_key']));
                $text = self::cleanAyahText($verse['text_qpc_hafs']);
                $entry = $sahih[$verse['verse_key']] ?? null;
                [$translation, $footnotes] = $entry ? self::parseTranslation($entry['t'], $entry['f'] ?? []) : [null, []];

                Ayah::query()->updateOrCreate(['surah_number' => $surah, 'number' => $number], [
                    'text' => $text,
                    'search_text' => Arabic::normalize($text),
                    'translation_en' => $translation,
                    'footnotes_en' => $footnotes,
                ]);
            }
        });

        return ['surahs' => count($chapters), 'ayahs' => count($verses)];
    }
}
