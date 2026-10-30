<?php

namespace App\Http\Controllers\Api;

use App\Content\ContentCache;
use App\Content\ErrorResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\HadithResource;
use App\Models\Hadith;
use Illuminate\Http\JsonResponse;

class HadithController extends Controller
{
    /** GET /hadiths/{id} : a Dorar hadith with every grading, strongest first. */
    public function show(string $id): JsonResponse
    {
        $payload = ctype_digit($id) ? ContentCache::remember(["hadith:{$id}"], "hadith:{$id}", function () use ($id): ?array {
            $hadith = Hadith::query()->with(['gradings', 'explanation'])->find((int) $id);

            return $hadith === null ? null : HadithResource::make($hadith)->resolve();
        }) : null;

        return $payload === null ? ErrorResponse::notFound('Hadith not found.') : response()->json($payload);
    }
}
