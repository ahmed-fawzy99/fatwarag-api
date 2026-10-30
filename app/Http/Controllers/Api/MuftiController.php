<?php

namespace App\Http\Controllers\Api;

use App\Content\ContentCache;
use App\Content\ErrorResponse;
use App\Content\FatwaListPage;
use App\Content\Locale;
use App\Http\Controllers\Controller;
use App\Models\Fatwa;
use App\Models\Mufti;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MuftiController extends Controller
{
    /** GET /muftis/{key} : a mufti's profile card, verbatim from the source's own pages. */
    public function show(string $key): JsonResponse
    {
        $payload = ContentCache::remember(['catalog'], "mufti:{$key}", function () use ($key): ?array {
            $mufti = Mufti::query()->where('key', $key)->first();

            return $mufti === null ? null : $this->card($mufti);
        });

        return $payload === null ? ErrorResponse::notFound('Mufti not found.') : response()->json($payload);
    }

    /** GET /muftis/{key}/fatwas?page=&locale= : the fatwas this mufti answered, newest first (same shape as a topic's). */
    public function fatwas(Request $request, string $key): JsonResponse
    {
        $locale = Locale::from($request);
        $page = max(1, (int) $request->query('page', 1));

        $payload = ContentCache::remember(['catalog', 'fatwa-lists'], "mufti-fatwas:{$key}:{$locale}:{$page}", function () use ($key, $locale, $page): ?array {
            $mufti = Mufti::query()->where('key', $key)->first();

            return $mufti === null ? null : FatwaListPage::of(Fatwa::query()->where('fatwas.mufti_id', $mufti->id), $locale, $page);
        });

        return $payload === null ? ErrorResponse::notFound('Mufti not found.') : response()->json($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Mufti $mufti): array
    {
        return [
            'key' => $mufti->key,
            'nameAr' => $mufti->name_ar,
            'nameEn' => $mufti->name_en ?? $mufti->name_ar,
            'kind' => $mufti->kind === 'institution' ? 'institution' : 'scholar',
            'bioAr' => $mufti->bio_ar,
            'bioEn' => $mufti->bio_en,
            'bioSourceUrl' => $mufti->source_url,
        ];
    }
}
