<?php

namespace App\Jobs;

use App\Models\Fatwa;
use App\Services\Alerts;
use App\Translation\BodyTranslator;
use App\Translation\Redact;
use App\Translation\TranslationBudgetExhausted;
use App\Translation\TranslationFailed;
use App\Translation\TranslationTimedOut;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Finishes a body translation that outlasted the reader's wait, so the next reader finds it saved. It runs after the
 * response has been sent (no queue worker needed). It takes over the fatwa's lock from the request that started the work
 * (same owner), so nobody else starts a second, paid translation meanwhile, and releases it when done.
 * Chunks already translated are reused.
 */
class TranslateFatwaBody
{
    use Dispatchable;

    /** Seconds during which a new chunk may still be started; the lock (see BodyTranslator::LOCK_SECONDS) outlives the last call. */
    public const WAIT = 200;

    public function __construct(public int $fatwaId, public string $locale, public string $lockName, public string $lockOwner, public ?float $lockExpiresAt = null) {}

    public function handle(BodyTranslator $translator, Alerts $alerts): void
    {
        $lock = Cache::restoreLock($this->lockName, $this->lockOwner);

        try {
            $fatwa = Fatwa::query()->find($this->fatwaId);

            if ($fatwa === null) {
                return;
            }

            set_time_limit(self::WAIT + 130);

            $translator->translate($fatwa, $this->locale, self::WAIT, $this->lockExpiresAt);
        } catch (TranslationBudgetExhausted) {
            // Paused until next month; the reader already got the Arabic.
        } catch (TranslationFailed|TranslationTimedOut $exception) {
            // The reader already got the Arabic; the next reader triggers a fresh attempt.
            $alerts->raise('translation', 'translation-body-failed', 'Opening a fatwa in English failed: the translation model returned an error.', ['error' => Redact::secrets($exception->getMessage())], 'Readers see the Arabic; the next reader triggers a fresh attempt.');
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            $lock->release();
        }
    }
}
