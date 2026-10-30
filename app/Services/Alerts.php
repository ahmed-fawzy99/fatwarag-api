<?php

namespace App\Services;

use App\Models\Alert;

/**
 * Records system failures for the admin and tells the owner on Telegram: on the first occurrence, and again when a
 * failure keeps repeating (every 10th occurrence), so a stuck job is noticed without flooding the chat.
 */
class Alerts
{
    public function __construct(private Telegram $telegram) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  bool  $notify  false records the alert for the admin without telling the owner (a caller that sends one
     *                        summary for many failures, e.g. a `common:generate` run)
     */
    public function raise(string $kind, string $fingerprint, string $message, array $context = [], ?string $nextStep = null, bool $notify = true): Alert
    {
        $alert = Alert::query()->firstOrNew(['fingerprint' => $fingerprint]);
        $isNew = ! $alert->exists || $alert->resolved_at !== null;

        $alert->fill([
            'kind' => $kind,
            'message' => $message,
            'context' => $context + ($nextStep ? ['next_step' => $nextStep] : []),
            'occurrences' => $isNew ? 1 : $alert->occurrences + 1,
            'first_seen_at' => $isNew ? now() : $alert->first_seen_at,
            'last_seen_at' => now(),
            'resolved_at' => null,
        ])->save();

        if ($notify && ($isNew || $alert->occurrences % 10 === 0)) {
            $sent = $this->telegram->send(implode("\n", array_filter([
                "⚠️ {$kind}",
                $message,
                'Since: '.$alert->first_seen_at->toDateTimeString().' UTC · '.$alert->occurrences.'×',
                $nextStep ? "Next: {$nextStep}" : null,
                $context ? 'Details: '.json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ])));

            if ($sent) {
                $alert->forceFill(['notified_at' => now()])->save();
            }
        }

        return $alert;
    }

    public function resolve(string $fingerprint): void
    {
        Alert::query()->where('fingerprint', $fingerprint)->whereNull('resolved_at')->update(['resolved_at' => now()]);
    }
}
