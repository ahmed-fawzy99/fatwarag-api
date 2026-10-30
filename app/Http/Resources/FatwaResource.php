<?php

namespace App\Http\Resources;

use App\Content\SegmentPresenter;
use App\Models\Fatwa;
use App\Models\FatwaTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fatwa (web/src/types/fatwa.ts). The internal source tier and the search text are never part of it.
 *
 * @property-read Fatwa $resource
 */
class FatwaResource extends JsonResource
{
    public static $wrap = null;

    private string $locale = 'ar';

    private ?SegmentPresenter $presenter = null;

    public function forLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function presentedBy(SegmentPresenter $presenter): static
    {
        $this->presenter = $presenter;

        return $this;
    }

    /**
     * Every relation the resource reads, so a list of fatwas costs a fixed number of queries.
     *
     * @return array<int, string>
     */
    public static function relations(): array
    {
        return ['source', 'mufti', 'topics', 'links.target', 'backlinks.fatwa', 'translations'];
    }

    /**
     * The paragraph lists of a fatwa and its translations, for resolving every hadith ref in one query.
     *
     * @return array<int, array<int, mixed>|null>
     */
    public static function paragraphLists(Fatwa $fatwa): array
    {
        $lists = [$fatwa->question, $fatwa->answer, $fatwa->source_summary];

        foreach ($fatwa->translations as $translation) {
            array_push($lists, $translation->question, $translation->answer, $translation->source_summary);
        }

        return $lists;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fatwa = $this->resource;
        $presenter = $this->presenter ?? SegmentPresenter::resolving(self::paragraphLists($fatwa));
        $translations = $this->locale === 'ar' ? collect() : $fatwa->translations->where('locale', $this->locale);
        $titleTranslation = $translations->firstWhere('kind', 'source') ?? $translations->firstWhere('kind', 'machine');

        return [
            'id' => $fatwa->public_id,
            'sourceKey' => $fatwa->source->key,
            'sourceFatwaId' => $fatwa->source_fatwa_id,
            'muftiKey' => $fatwa->mufti?->key ?? $fatwa->source->key,
            'origin' => $fatwa->origin,
            'dateHijri' => FatwaListItemResource::hijri($fatwa),
            'dateGregorian' => $fatwa->date_gregorian?->toDateString(),
            'topicKeys' => $fatwa->topics->pluck('key')->values()->all(),
            'seeAlso' => $fatwa->links
                ->filter(fn ($link) => $link->target !== null)
                ->map(fn ($link) => ['sourceFatwaId' => $link->target_source_fatwa_id, 'url' => $link->target->url])
                ->values()
                ->all(),
            'referencedBy' => $fatwa->backlinks->pluck('fatwa.public_id')->filter()->unique()->values()->all(),
            // The English title, even before the body is translated: the source's own, else the machine's.
            'titleEn' => $titleTranslation?->title,
            'titleEnTranslated' => $titleTranslation?->kind === 'machine',
            'ar' => FatwaTextResource::make($fatwa)->presentedBy($presenter)->resolve(),
            'en' => $this->translation($translations->firstWhere('kind', 'source'), $presenter, $fatwa),
            'enMachine' => $this->translation($translations->firstWhere('kind', 'machine'), $presenter, $fatwa),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function translation(?FatwaTranslation $translation, SegmentPresenter $presenter, Fatwa $fatwa): ?array
    {
        // A machine translation that only has its title (titles are translated up front) has no body yet.
        if ($translation === null || ! $translation->hasBody()) {
            return null;
        }

        return FatwaTextResource::make($translation)->presentedBy($presenter)->withFallbackUrl($fatwa->url)->resolve();
    }
}
