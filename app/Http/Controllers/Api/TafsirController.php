<?php

namespace App\Http\Controllers\Api;

use App\Content\ContentCache;
use App\Content\ErrorResponse;
use App\Http\Controllers\Controller;
use App\Models\Ayah;
use App\Models\Tafsir;
use App\Models\TafsirPassage;
use Illuminate\Http\JsonResponse;

class TafsirController extends Controller
{
    /** The tafsirs the Explain panel offers, in the order of the web type (TafsirKey). */
    private const KEYS = ['muyassar', 'ibnKathir', 'saadi'];

    /** GET /quran/tafsir/{surah}/{ayah} : the passage of each tafsir that covers the verse (null where one has none). */
    public function show(string $surah, string $ayah): JsonResponse
    {
        if (! ctype_digit($surah) || ! ctype_digit($ayah)) {
            return ErrorResponse::notFound('Verse not found.');
        }

        $payload = ContentCache::remember(["surah:{$surah}"], "tafsir:{$surah}:{$ayah}", fn () => $this->entry((int) $surah, (int) $ayah));

        return $payload === null ? ErrorResponse::notFound('Verse not found.') : response()->json($payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entry(int $surah, int $ayah): ?array
    {
        if (! Ayah::query()->where(['surah_number' => $surah, 'number' => $ayah])->exists()) {
            return null;
        }

        $passages = TafsirPassage::query()
            ->where('surah_number', $surah)
            ->where('ayah_from', '<=', $ayah)
            ->where('ayah_to', '>=', $ayah)
            ->orderBy('ayah_to')
            ->get()
            ->groupBy('tafsir_id');

        $keys = Tafsir::query()->whereIn('key', self::KEYS)->pluck('id', 'key');

        $texts = [];

        foreach (self::KEYS as $key) {
            // The narrowest passage wins if a tafsir has overlapping ranges.
            $texts[$key] = isset($keys[$key]) ? $passages->get($keys[$key])?->first()?->text : null;
        }

        return ['surah' => $surah, 'ayah' => $ayah, 'texts' => $texts];
    }
}
