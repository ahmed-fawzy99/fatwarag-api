<?php

namespace App\Http\Controllers\Api;

use App\Content\ContentCache;
use App\Content\ErrorResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\SurahResource;
use App\Models\Surah;
use Illuminate\Http\JsonResponse;

class QuranController extends Controller
{
    /** GET /quran/surahs/{number} : the whole surah with Saheeh International and footnotes. */
    public function surah(string $number): JsonResponse
    {
        $payload = ctype_digit($number) ? ContentCache::remember(["surah:{$number}"], "surah:{$number}", function () use ($number): ?array {
            $surah = Surah::query()->with('ayahs')->find((int) $number);

            return $surah === null ? null : SurahResource::make($surah)->resolve();
        }) : null;

        return $payload === null ? ErrorResponse::notFound('Surah not found.') : response()->json($payload);
    }
}
