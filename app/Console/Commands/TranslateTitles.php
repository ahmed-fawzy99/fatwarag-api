<?php

namespace App\Console\Commands;

use App\Models\TranslationJob;
use App\Services\Alerts;
use App\Translation\Redact;
use App\Translation\TitleTranslator;
use App\Translation\TranslationFailed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class TranslateTitles extends Command
{
    protected $signature = 'translations:titles {--locale=en : Target locale} {--max-cost=15 : Stop once this many USD have been spent in this run}';

    protected $description = 'Translate fatwa titles that have no translation yet (Haiku, batches of 40). Safe to re-run; it only does what is missing.';

    private const MAX_CONSECUTIVE_FAILURES = 3;

    public function handle(TitleTranslator $titles, Alerts $alerts): int
    {
        $locale = (string) $this->option('locale');

        // A second run would translate the same pending titles and pay for them twice.
        $lock = Cache::lock("translations:titles:{$locale}", 2 * 3600);

        if (! $lock->get()) {
            $this->warn('Another translations:titles run is active; not starting a second one.');

            return self::SUCCESS;
        }

        try {
            return $this->translatePending($titles, $alerts, $locale);
        } finally {
            $lock->release();
        }
    }

    private function translatePending(TitleTranslator $titles, Alerts $alerts, string $locale): int
    {
        $maxCost = (float) $this->option('max-cost');
        $pending = $titles->pendingCount($locale);

        $job = TranslationJob::query()->firstOrCreate(['locale' => $locale, 'scope' => 'fatwa_titles']);

        if ($pending === 0) {
            $this->info('Every fatwa title already has a translation.');

            return self::SUCCESS;
        }

        $job->update(['status' => 'running', 'stop_reason' => null, 'total' => $job->done + $job->failed + $pending]);

        $spent = 0.0;
        $cursor = 0;
        $failures = 0;
        $failedBatches = 0;
        $lastError = null;
        $stopReason = null;

        while (true) {
            $batch = $titles->nextBatch($locale, $cursor);

            if ($batch->isEmpty()) {
                break;
            }

            if ($spent >= $maxCost) {
                $stopReason = sprintf('cost cap reached (%.2f of %.2f USD)', $spent, $maxCost);
                break;
            }

            $cursor = $batch->last()->id;

            try {
                $result = $titles->translate($batch, $locale);
                $spent += $result['cost'];
                $failures = 0;
                $failedBatches = 0;
                $lastError = null;
                $job->increment('done', $result['saved']);
            } catch (TranslationFailed $exception) {
                $failures++;
                $failedBatches++;
                $lastError = $exception->getMessage();
                $job->increment('failed', $batch->count());
                $this->warn('Batch failed: '.$exception->getMessage());

                if ($failures >= self::MAX_CONSECUTIVE_FAILURES) {
                    $stopReason = 'the translation model kept failing: '.$exception->getMessage();
                    break;
                }
            }
        }

        if ($failedBatches > 0) {
            $stopReason ??= "{$failedBatches} batch(es) failed: {$lastError}";
            $alerts->raise('translation', 'translation-titles-failing', 'Fatwa title translation hit errors from the translation model.', ['error' => Redact::secrets((string) $lastError), 'failedBatches' => $failedBatches], 'Failed titles are retried on the next hourly run; check the OpenRouter key and credit.');
        }

        $job->update(['status' => $stopReason ? 'stopped' : 'done', 'stop_reason' => $stopReason]);

        if ($failedBatches === 0) {
            $alerts->resolve('translation-titles-failing');
        }

        $this->info(sprintf('Titles translated: %d, failed: %d, spent %.4f USD.%s', $job->done, $job->failed, $spent, $stopReason ? " Stopped: {$stopReason}." : ''));

        return self::SUCCESS;
    }
}
