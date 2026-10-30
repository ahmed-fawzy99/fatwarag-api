<?php

namespace App\Http\Controllers\Api;

use App\Content\ContentCache;
use App\Http\Controllers\Controller;
use App\Models\Source;
use Illuminate\Http\JsonResponse;

class SourceController extends Controller
{
    /** GET /sources : the fatwa sources. The internal tier is never sent, and foundations are not listed. */
    public function index(): JsonResponse
    {
        return response()->json(ContentCache::remember(['catalog'], 'sources', fn () => Source::query()->where('kind', 'fatwa')->where('is_enabled', true)->orderBy('tier')->orderBy('id')->get()
            ->map(fn (Source $source) => [
                'key' => $source->key,
                'nameAr' => $source->name_ar,
                'nameEn' => $source->name_en,
                'homeUrl' => $source->base_url,
            ])->all()));
    }
}
