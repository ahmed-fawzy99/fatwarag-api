<?php

namespace App\Services;

use App\Models\AiUsage;
use App\Models\Setting;

/**
 * Tracks AI cost against the monthly budget. One-off indexing costs are recorded but don't count.
 * Budget alerts go to Telegram at 50%, 80% and 100%, once each per month.
 */
class AiSpend
{
    private const THRESHOLDS = [50, 80, 100];

    public function __construct(private Telegram $telegram) {}

    /**
     * @param  int|null  $durationMs  how long the call took, so slow stages can be found (never the question itself)
     */
    public function record(string $purpose, string $model, int $inputTokens, int $outputTokens, float $costUsd, bool $countsTowardBudget = true, ?int $durationMs = null): void
    {
        AiUsage::query()->create([
            'purpose' => $purpose,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => $costUsd,
            'counts_toward_budget' => $countsTowardBudget,
            'duration_ms' => $durationMs,
        ]);

        if ($countsTowardBudget) {
            $this->notifyThresholds();
        }
    }

    /**
     * Estimated cost in USD from the list prices in `fatwarag.ai_prices` (summary, translation, embedding, rerank).
     */
    /**
     * Milliseconds since an `hrtime(true)` start, for `record()`'s duration.
     */
    public static function millisecondsSince(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    public static function estimate(string $priceKey, int $inputTokens, int $outputTokens = 0): float
    {
        $prices = config("fatwarag.ai_prices.{$priceKey}", ['input' => 0, 'output' => 0]);

        return ($inputTokens * (float) $prices['input'] + $outputTokens * (float) $prices['output']) / 1_000_000;
    }

    public function monthlyBudget(): float
    {
        return (float) Setting::get('monthly_budget_usd', config('fatwarag.limits.monthly_budget_usd'));
    }

    public function spentThisMonth(): float
    {
        return (float) AiUsage::query()
            ->where('counts_toward_budget', true)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_usd');
    }

    public function isExhausted(): bool
    {
        return $this->spentThisMonth() >= $this->monthlyBudget();
    }

    private function notifyThresholds(): void
    {
        $budget = $this->monthlyBudget();

        if ($budget <= 0) {
            return;
        }

        $percent = $this->spentThisMonth() / $budget * 100;
        $month = now()->format('Y-m');
        $notified = Setting::get("budget_alerts.{$month}", []);

        foreach (self::THRESHOLDS as $threshold) {
            if ($percent >= $threshold && ! in_array($threshold, $notified, true)) {
                $notified[] = $threshold;
                Setting::put("budget_alerts.{$month}", $notified);
                $this->telegram->send(sprintf(
                    '💰 AI spend reached %d%% of the monthly budget (%.2f of %.2f USD).%s',
                    $threshold,
                    $this->spentThisMonth(),
                    $budget,
                    $threshold >= 100 ? "\nSummaries and first-time translations are paused until next month." : '',
                ));
            }
        }
    }
}
