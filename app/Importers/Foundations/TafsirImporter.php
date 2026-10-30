<?php

namespace App\Importers\Foundations;

use App\Models\Surah;
use App\Models\Tafsir;
use App\Models\TafsirPassage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Imports the three tafsirs as passages that each cover a range of ayahs:
 *  - muyassar: Quran.com edition 16 (grouped verses are listed once, at their first verse)
 *  - ibnKathir: QUL 22 via spa5k (full harakat; a boundary bug at 2:1 is repaired)
 *  - saadi: QUL 308 via spa5k (mid-sentence line breaks are rejoined)
 * Safe to re-run: each tafsir's passages are replaced inside one transaction.
 */
class TafsirImporter
{
    /** The bracketed basmala that opens the surah's own text in QUL's file (its later letters carry different harakat). */
    private const BASMALA_OPENING = '[بِسْمِ';

    private const BAQARA_HEADING = 'تَفْسِيرُ سُورَةِ الْبَقَرَةِ';

    /** @var array<string, array{name_ar: string, name_en: string, source_url: string, is_default: bool}> */
    private const TAFSIRS = [
        'muyassar' => ['name_ar' => 'التفسير الميسر', 'name_en' => 'Al-Tafsir al-Muyassar', 'source_url' => 'https://quran.com/1/tafsirs/ar-tafsir-muyassar', 'is_default' => true],
        'ibnKathir' => ['name_ar' => 'تفسير ابن كثير', 'name_en' => 'Tafsir Ibn Kathir', 'source_url' => 'https://qul.tarteel.ai/resources/tafsir/22', 'is_default' => false],
        'saadi' => ['name_ar' => 'تفسير السعدي', 'name_en' => "Tafsir as-Sa'di", 'source_url' => 'https://qul.tarteel.ai/resources/tafsir/308', 'is_default' => false],
    ];

    /**
     * Collapses consecutive ayahs with identical text into one passage covering the range.
     *
     * @param  array<int, string>  $textByAyah  ayah number => text
     * @return list<array{from: int, to: int, text: string}>
     */
    public static function runs(array $textByAyah): array
    {
        ksort($textByAyah);
        $passages = [];

        foreach ($textByAyah as $ayah => $text) {
            $last = array_key_last($passages);

            if ($last !== null && $passages[$last]['text'] === $text && $passages[$last]['to'] === $ayah - 1) {
                $passages[$last]['to'] = $ayah;
            } else {
                $passages[] = ['from' => $ayah, 'to' => $ayah, 'text' => $text];
            }
        }

        return $passages;
    }

    /**
     * Quran.com lists a grouped passage once, at its first verse. The verses omitted after it, up to the next listed
     * verse (or the end of the surah), are the passage's range.
     *
     * @param  list<array{verse_key: string, text: string}>  $entries
     * @return list<array{from: int, to: int, text: string}>
     */
    public static function quranComPassages(array $entries, int $ayahCount): array
    {
        $entries = collect($entries)
            ->map(fn ($e) => ['from' => (int) explode(':', $e['verse_key'])[1], 'text' => $e['text']])
            ->sortBy('from')->values()->all();

        return collect($entries)->map(fn ($e, $i) => [
            'from' => $e['from'],
            'to' => isset($entries[$i + 1]) ? $entries[$i + 1]['from'] - 1 : max($ayahCount, $e['from']),
            'text' => $e['text'],
        ])->all();
    }

    /**
     * Rejoins hard-wrapped lines: a single line break after a letter or comma is a wrap, not a paragraph. Breaks after
     * sentence-ending punctuation, blank lines and the start of a numbered {n} section stay.
     */
    public static function joinWrappedLines(string $text): string
    {
        return preg_replace('/(?<=[\p{L}\p{M}،])[ \t]*\n(?![\n{])[ \t]*/u', ' ', $text);
    }

    /**
     * The first part of QUL's Ibn Kathir text for 2:1 is the end of the commentary on 1:7 (al-Fatiha). Moves it there.
     * Left untouched when the expected markers aren't found.
     *
     * @param  array<int, array<int, string>>  $bySurah  surah => ayah => text
     * @return array<int, array<int, string>>
     */
    public static function fixIbnKathirBoundary(array $bySurah): array
    {
        $text = $bySurah[2][1] ?? null;
        $heading = $text === null ? false : strpos($text, self::BAQARA_HEADING);
        $split = $heading === false ? false : strrpos(substr($text, 0, $heading), self::BASMALA_OPENING);

        if ($split === false || $split === 0 || ! isset($bySurah[1][7])) {
            return $bySurah;
        }

        $bySurah[1][7] = rtrim($bySurah[1][7])."\n\n".trim(substr($text, 0, $split));
        $bySurah[2][1] = substr($text, $split);

        return $bySurah;
    }

    /**
     * @param  list<string>|null  $only  tafsir keys to import (default: all three)
     * @return array<string, int> passages imported per tafsir
     */
    public function import(string $rawDir, ?array $only = null): array
    {
        $counts = [];

        foreach (self::TAFSIRS as $key => $meta) {
            Tafsir::query()->updateOrCreate(['key' => $key], $meta);

            if ($only !== null && ! in_array($key, $only, true)) {
                continue;
            }

            $passages = match ($key) {
                'muyassar' => $this->muyassar($rawDir),
                'ibnKathir' => $this->qul($rawDir.'/ar-tafsir-ibn-kathir', fn ($rows) => self::fixIbnKathirBoundary($rows)),
                'saadi' => $this->qul($rawDir.'/ar-tafsir-as-saadi', null, fn ($t) => self::joinWrappedLines($t)),
            };

            if (array_sum(array_map('count', $passages)) === 0) {
                // A wrong path or an empty folder must never wipe the passages already stored.
                Log::warning("Tafsir import: no passages found for {$key} in {$rawDir}; keeping the stored ones.");
                $counts[$key] = 0;

                continue;
            }

            $counts[$key] = $this->store(Tafsir::query()->where('key', $key)->value('id'), $passages);
        }

        return $counts;
    }

    /**
     * @return array<int, list<array{from: int, to: int, text: string}>>
     */
    private function muyassar(string $rawDir): array
    {
        $ayahCounts = Surah::query()->pluck('ayah_count', 'number');
        $bySurah = [];

        foreach ($ayahCounts as $surah => $count) {
            $path = "{$rawDir}/qc/16_{$surah}.json";

            if (is_file($path)) {
                $entries = json_decode(File::get($path), true)['tafsirs'] ?? [];
                $bySurah[$surah] = self::quranComPassages(array_values(array_filter($entries, fn ($e) => trim($e['text'] ?? '') !== '')), $count);
            }
        }

        return $bySurah;
    }

    /**
     * @return array<int, list<array{from: int, to: int, text: string}>>
     */
    private function qul(string $dir, ?callable $fixBoundaries = null, ?callable $cleanText = null): array
    {
        $rows = [];

        foreach (Surah::query()->pluck('number') as $surah) {
            $path = "{$dir}/{$surah}.json";

            if (is_file($path)) {
                foreach (json_decode(File::get($path), true) as $entry) {
                    $rows[$surah][(int) $entry['ayah']] = (string) $entry['text'];
                }
            }
        }

        if ($fixBoundaries) {
            $rows = $fixBoundaries($rows);
        }

        return array_map(function (array $textByAyah) use ($cleanText) {
            $textByAyah = array_filter(array_map(fn ($t) => trim($cleanText ? $cleanText($t) : $t), $textByAyah), fn ($t) => $t !== '');

            return self::runs($textByAyah);
        }, $rows);
    }

    /**
     * @param  array<int, list<array{from: int, to: int, text: string}>>  $bySurah
     */
    private function store(int $tafsirId, array $bySurah): int
    {
        $total = 0;

        DB::transaction(function () use ($tafsirId, $bySurah, &$total) {
            TafsirPassage::query()->where('tafsir_id', $tafsirId)->delete();

            foreach ($bySurah as $surah => $passages) {
                $rows = array_map(fn ($p) => [
                    'tafsir_id' => $tafsirId, 'surah_number' => $surah, 'ayah_from' => $p['from'], 'ayah_to' => $p['to'], 'text' => $p['text'],
                ], $passages);

                foreach (array_chunk($rows, 100) as $chunk) {
                    TafsirPassage::query()->insert($chunk);
                }

                $total += count($rows);
            }
        });

        return $total;
    }
}
