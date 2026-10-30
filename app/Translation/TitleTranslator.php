<?php

namespace App\Translation;

use App\Models\Fatwa;
use App\Models\FatwaTranslation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Translates fatwa titles in batches, up front, because they appear in lists before anyone opens the fatwa.
 * Only fatwas with no translation for the locale are touched, so a source's own translation is never overwritten.
 */
class TitleTranslator
{
    public function __construct(private TextTranslator $translator) {}

    /**
     * The next batch of fatwas that still need a title, after the given id.
     *
     * @return Collection<int, Fatwa>
     */
    public function nextBatch(string $locale, int $afterId = 0): Collection
    {
        return $this->pending($locale)
            ->where('fatwas.id', '>', $afterId)
            ->orderBy('fatwas.id')
            ->limit((int) config('fatwarag.translation.batch_size'))
            ->get(['fatwas.id', 'fatwas.title']);
    }

    public function pendingCount(string $locale): int
    {
        return $this->pending($locale)->count();
    }

    /**
     * @param  Collection<int, Fatwa>  $fatwas
     * @return array{saved: int, cost: float}
     *
     * @throws TranslationFailed
     */
    public function translate(Collection $fatwas, string $locale): array
    {
        $result = $this->translator->translate($fatwas->pluck('title')->all(), 'titles', countsTowardBudget: false, timeout: 120);
        $saved = 0;

        foreach ($fatwas->values() as $index => $fatwa) {
            // firstOrCreate: a translation that appeared meanwhile (e.g. the source's own) always wins.
            $translation = FatwaTranslation::query()->firstOrCreate(
                ['fatwa_id' => $fatwa->id, 'locale' => $locale],
                ['kind' => 'machine', 'title' => $result['translations'][$index], 'model' => config('fatwarag.openrouter.models.translation')],
            );
            $saved += $translation->wasRecentlyCreated ? 1 : 0;
        }

        return ['saved' => $saved, 'cost' => $result['cost']];
    }

    private function pending(string $locale): Builder
    {
        return Fatwa::query()->whereNotExists(
            fn ($query) => $query->selectRaw('1')->from('fatwa_translations')->whereColumn('fatwa_translations.fatwa_id', 'fatwas.id')->where('fatwa_translations.locale', $locale),
        );
    }
}
