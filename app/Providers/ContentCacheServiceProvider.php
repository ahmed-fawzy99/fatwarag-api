<?php

namespace App\Providers;

use App\Content\ContentCache;
use App\Models\Ayah;
use App\Models\CommonQuestion;
use App\Models\Fatwa;
use App\Models\FatwaLink;
use App\Models\FatwaTranslation;
use App\Models\GlossaryTerm;
use App\Models\Hadith;
use App\Models\HadithExplanation;
use App\Models\HadithGrading;
use App\Models\HadithQuote;
use App\Models\Mufti;
use App\Models\Setting;
use App\Models\Source;
use App\Models\Surah;
use App\Models\TafsirPassage;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

/**
 * Clears the content cache tags when the content behind them changes.
 *
 * Only model events are seen here: bulk queries and pivot changes (a fatwa's topics) fire none, so the lists are also
 * cleared hourly and by `content:cache-clear`.
 */
class ContentCacheServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->onChange(Fatwa::class, function (Fatwa $fatwa): void {
            if (! $this->changed($fatwa)) {
                return;
            }

            ContentCache::forgetFatwa($fatwa->public_id);

            // Lists show titles; the sitemap shows when a fatwa last changed.
            ContentCache::forgetLists();
        });

        $this->onChange(FatwaTranslation::class, function (FatwaTranslation $translation): void {
            if (! $this->changed($translation)) {
                return;
            }

            $publicId = Fatwa::query()->whereKey($translation->fatwa_id)->value('public_id');

            if ($publicId !== null) {
                ContentCache::forgetFatwa($publicId);
            }

            // Lists show titles, and the sitemap knows which fatwas have an English page.
            if (! $translation->exists || $translation->wasRecentlyCreated || $translation->wasChanged(['title', 'answer'])) {
                ContentCache::forgetLists();
            }
        });

        $this->onChange(FatwaLink::class, function (FatwaLink $link): void {
            Fatwa::query()->whereIn('id', array_filter([$link->fatwa_id, $link->target_fatwa_id]))->pluck('public_id')->each(ContentCache::forgetFatwa(...));
        });

        $this->onChange(HadithQuote::class, function (HadithQuote $quote): void {
            // A lookup finishing (found, or settled as not found) changes how every fatwa quoting it renders.
            if ($quote->wasChanged(['status', 'hadith_id'])) {
                ContentCache::forgetFatwasQuoting($quote->quote_key);
                ContentCache::forget(['hadith-lists']);
            }
        });

        $this->onChange(Hadith::class, fn (Hadith $hadith) => ContentCache::forget(["hadith:{$hadith->id}", 'hadith-lists']));
        $this->onChange(HadithGrading::class, fn (HadithGrading $grading) => ContentCache::forget(["hadith:{$grading->hadith_id}"]));
        $this->onChange(HadithExplanation::class, fn (HadithExplanation $explanation) => $explanation->hadiths()->pluck('id')->each(fn ($id) => ContentCache::forget(["hadith:{$id}"])));

        $this->onChange(Surah::class, fn (Surah $surah) => ContentCache::forget(["surah:{$surah->number}"]));
        $this->onChange(Ayah::class, fn (Ayah $ayah) => ContentCache::forget(["surah:{$ayah->surah_number}"]));
        $this->onChange(TafsirPassage::class, fn (TafsirPassage $passage) => ContentCache::forget(["surah:{$passage->surah_number}"]));

        $this->onChange(Topic::class, fn () => ContentCache::forget(['topics']));
        $this->onChange(Source::class, fn () => ContentCache::forget(['catalog']));
        $this->onChange(Mufti::class, fn () => ContentCache::forget(['catalog']));
        $this->onChange(GlossaryTerm::class, fn () => ContentCache::forget(['catalog']));
        $this->onChange(CommonQuestion::class, fn () => ContentCache::forget(['common-questions']));
        $this->onChange(Setting::class, fn (Setting $setting) => $setting->key === 'glossary_intro' ? ContentCache::forget(['catalog']) : null);
    }

    /** A save that wrote nothing (the same data imported again) leaves the cache alone. */
    private function changed(Model $model): bool
    {
        return ! $model->exists || $model->wasRecentlyCreated || $model->wasChanged();
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function onChange(string $model, callable $handler): void
    {
        foreach (['saved', 'deleted'] as $event) {
            $model::{$event}($handler);
        }
    }
}
