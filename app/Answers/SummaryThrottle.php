<?php

namespace App\Answers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * How many summaries (JSON or stream) one visitor may start per minute (`fatwarag.summary.per_client_per_minute`),
 * on top of the 3 per answer. The visitor is the IP IdentifyClient settled on.
 */
class SummaryThrottle
{
    public function allows(Request $request): bool
    {
        return RateLimiter::attempt(
            'summary:'.hash('sha256', self::client($request)),
            max(1, (int) config('fatwarag.summary.per_client_per_minute', 10)),
            fn (): bool => true,
            60,
        ) !== false;
    }

    public static function client(Request $request): string
    {
        return (string) ($request->attributes->get('client_ip') ?? $request->ip());
    }
}
