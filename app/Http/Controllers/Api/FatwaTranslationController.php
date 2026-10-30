<?php

namespace App\Http\Controllers\Api;

use App\Content\Crawlers;
use App\Content\ErrorResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\FatwaTextResource;
use App\Jobs\TranslateFatwaBody;
use App\Limits\TranslationLimiter;
use App\Models\Fatwa;
use App\Models\FatwaTranslation;
use App\Services\AiSpend;
use App\Services\Alerts;
use App\Translation\BodyTranslator;
use App\Translation\Redact;
use App\Translation\TranslationBudgetExhausted;
use App\Translation\TranslationFailed;
use App\Translation\TranslationTimedOut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class FatwaTranslationController extends Controller
{
    /**
     * GET /fatwas/{publicId}/translation?locale=en : the English text, translated on the first request and reused after.
     *
     * Every failure maps to a status the page answers by showing the Arabic: 504 timeout (also "someone is already
     * translating it"), 502 upstream_failed, 503 budget_exhausted, 429 rate_limited. Crawlers never trigger a translation.
     */
    public function show(Request $request, string $publicId, BodyTranslator $translator, AiSpend $spend, Alerts $alerts, TranslationLimiter $limiter): JsonResponse
    {
        $locale = (string) $request->query('locale', 'en');

        if ($locale !== 'en') {
            return ErrorResponse::validation('Only English translations are available.');
        }

        $fatwa = Fatwa::query()->where('public_id', $publicId)->first();

        if ($fatwa === null) {
            return ErrorResponse::notFound('Fatwa not found.');
        }

        $stored = $this->stored($fatwa, $locale);

        if ($stored?->hasBody()) {
            return $this->respond($stored, $fatwa);
        }

        // The source publishes its own English for this fatwa (title only so far): ours would never replace it.
        if ($stored?->kind === 'source') {
            return ErrorResponse::notFound('No translation is available.');
        }

        if (Crawlers::isCrawler($request)) {
            return ErrorResponse::notFound('No translation is available yet.');
        }

        if ($spend->isExhausted()) {
            return ErrorResponse::make('budget_exhausted', 'Translations are paused until next month.', 503);
        }

        try {
            $lock = $translator->lockFor($fatwa, $locale);
            $lockExpiresAt = microtime(true) + BodyTranslator::LOCK_SECONDS;
            $acquired = $lock->get();
        } catch (Throwable $exception) {
            $alerts->raise('translation', 'translation-lock-failed', 'Fatwa translation could not take its lock (is the cache down?).', ['error' => Redact::secrets($exception->getMessage())], 'Readers see the Arabic until the cache is back.');

            return ErrorResponse::make('upstream_failed', 'The translation could not be made.', 502);
        }

        // Someone is already translating this fatwa: show the Arabic now rather than wait on them.
        if (! $acquired) {
            return ErrorResponse::make('timeout', 'This fatwa is being translated; try again in a moment.', 504);
        }

        $handedOver = false;

        try {
            $stored = $this->stored($fatwa, $locale);

            if ($stored?->hasBody()) {
                return $this->respond($stored, $fatwa);
            }

            try {
                $allowed = $limiter->attempt((string) $request->attributes->get('client_ip', $request->ip()));
            } catch (Throwable $exception) {
                $alerts->raise('translation', 'translation-limiter-failed', 'Fatwa translation could not check its rate limit (is Redis down?).', ['error' => Redact::secrets($exception->getMessage())], 'Readers see the Arabic until the cache is back.');

                return ErrorResponse::make('upstream_failed', 'The translation could not be made.', 502);
            }

            if (! $allowed) {
                return ErrorResponse::make('rate_limited', 'Too many translations requested. Please try again later.', 429);
            }

            return $this->respond($translator->translate($fatwa, $locale, lockExpiresAt: $lockExpiresAt), $fatwa);
        } catch (TranslationTimedOut) {
            // Keep going after this response, so a long fatwa is ready for the next reader instead of timing out again.
            // The job takes the lock over, so nobody starts a second paid translation meanwhile.
            TranslateFatwaBody::dispatch($fatwa->id, $locale, $translator->lockName($fatwa, $locale), $lock->owner(), $lockExpiresAt)->afterResponse();
            $handedOver = true;

            return ErrorResponse::make('timeout', 'The translation is taking too long.', 504);
        } catch (TranslationBudgetExhausted) {
            return ErrorResponse::make('budget_exhausted', 'Translations are paused until next month.', 503);
        } catch (TranslationFailed $exception) {
            $alerts->raise('translation', 'translation-body-failed', 'Opening a fatwa in English failed: the translation model returned an error.', ['error' => Redact::secrets($exception->getMessage())], 'Readers see the Arabic; the next reader triggers a fresh attempt.');

            return ErrorResponse::make('upstream_failed', 'The translation could not be made.', 502);
        } finally {
            if (! $handedOver) {
                $lock->release();
            }
        }
    }

    private function stored(Fatwa $fatwa, string $locale): ?FatwaTranslation
    {
        return FatwaTranslation::query()->where(['fatwa_id' => $fatwa->id, 'locale' => $locale])->first();
    }

    private function respond(FatwaTranslation $translation, Fatwa $fatwa): JsonResponse
    {
        return response()->json(FatwaTextResource::make($translation)->withFallbackUrl($fatwa->url)->resolve());
    }
}
