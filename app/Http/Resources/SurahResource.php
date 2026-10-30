<?php

namespace App\Http\Resources;

use App\Models\Surah;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Surah (web/src/types/foundations.ts): the whole surah, so `partial` is always false.
 *
 * @property-read Surah $resource
 */
class SurahResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->resource->number,
            'nameAr' => $this->resource->name_ar,
            'nameEn' => $this->resource->name_en,
            'partial' => false,
            'ayat' => $this->resource->ayahs->map(fn ($ayah) => [
                'ayah' => $ayah->number,
                'ar' => $ayah->text,
                'en' => $ayah->translation_en ?? '',
                'enFootnotes' => $ayah->footnotes_en ?? [],
            ])->all(),
        ];
    }
}
