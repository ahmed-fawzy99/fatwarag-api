<?php

namespace App\Http\Resources;

use App\Models\Hadith;
use App\Models\HadithGrading;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Hadith (web/src/types/foundations.ts). Hadiths come from Dorar, so the collection is "dorar" and the number is our id.
 *
 * @property-read Hadith $resource
 */
class HadithResource extends JsonResource
{
    public static $wrap = null;

    private const LEVEL_LABELS_EN = [
        'sahih' => 'Sahih',
        'hasan' => 'Hasan',
        'daif' => "Da'if (weak)",
        'very_daif' => "Very da'if (very weak)",
        'mawdu' => "Mawdu' (fabricated)",
        'other' => 'Other',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hadith = $this->resource;
        $explanation = $hadith->explanation;

        return [
            'collection' => 'dorar',
            'number' => $hadith->id,
            'displayNumber' => $hadith->id,
            'section' => null,
            'sectionAr' => null,
            'sectionEn' => null,
            'ar' => $hadith->text,
            'en' => $hadith->translation_en,
            'grades' => $hadith->gradings
                ->sortBy([fn (HadithGrading $a, HadithGrading $b) => $a->level->strength() <=> $b->level->strength(), fn (HadithGrading $a, HadithGrading $b) => $a->position <=> $b->position])
                ->map(fn (HadithGrading $grading) => $this->grade($grading))
                ->values()
                ->all(),
            'explanation' => $explanation === null ? null : [
                'paragraphs' => array_values(array_filter(array_map('trim', preg_split('/\R+/u', $explanation->text) ?: []), fn (string $line): bool => $line !== '')),
                'sourceName' => 'الدرر السنية (dorar.net)',
                'sourceUrl' => $explanation->source_url,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function grade(HadithGrading $grading): array
    {
        return [
            'scholarAr' => $grading->scholar,
            'scholarEn' => $grading->scholar_en ?? $grading->scholar,
            'level' => $grading->level->value,
            'labelAr' => $grading->phrase,
            'labelEn' => self::LEVEL_LABELS_EN[$grading->level->value],
            'explainedAr' => null,
            'bookAr' => $grading->book,
            'page' => $grading->reference,
            'sourceUrl' => $grading->sourceUrl(),
        ];
    }
}
