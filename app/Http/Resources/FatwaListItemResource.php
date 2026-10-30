<?php

namespace App\Http\Resources;

use App\Models\Fatwa;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FatwaListItem (web/src/types/fatwa.ts): what a list needs, without the text.
 *
 * @property-read Fatwa $resource
 */
class FatwaListItemResource extends JsonResource
{
    public static $wrap = null;

    private string $locale = 'ar';

    public function forLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public static function relations(): array
    {
        return ['source', 'mufti', 'translations'];
    }

    /**
     * @return array{day: int, month: int, year: int}|null
     */
    public static function hijri(Fatwa $fatwa): ?array
    {
        if ($fatwa->hijri_year === null || $fatwa->hijri_month === null || $fatwa->hijri_day === null) {
            return null;
        }

        return ['day' => $fatwa->hijri_day, 'month' => $fatwa->hijri_month, 'year' => $fatwa->hijri_year];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fatwa = $this->resource;
        $translation = $this->locale === 'ar' ? null : $fatwa->translations->firstWhere('locale', $this->locale);

        return [
            'id' => $fatwa->public_id,
            'sourceKey' => $fatwa->source->key,
            'sourceFatwaId' => $fatwa->source_fatwa_id,
            'dateHijri' => self::hijri($fatwa),
            'dateGregorian' => $fatwa->date_gregorian?->toDateString(),
            'muftiKey' => $fatwa->mufti?->key ?? $fatwa->source->key,
            'title' => $translation?->title ?? $fatwa->title,
            // Only an AI title carries the "translated" icon; a source's own translation does not.
            'titleTranslated' => $translation?->kind === 'machine',
        ];
    }
}
