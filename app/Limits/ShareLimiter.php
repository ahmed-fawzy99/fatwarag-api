<?php

namespace App\Limits;

use Illuminate\Support\Facades\RateLimiter;

/**
 * How many answers one visitor may share per hour. The visitor is identified by a keyed hash of their IP bucket
 * (IPv6 by its /64 prefix), which is also what `shared_answers.ip_hash` keeps; no plain IP is stored.
 */
class ShareLimiter
{
    public const PER_HOUR = 10;

    public static function ipHash(string $ip): string
    {
        return hash_hmac('sha256', QuestionLimiter::bucket($ip), (string) config('app.key'));
    }

    public function tooMany(string $ipHash): bool
    {
        return RateLimiter::tooManyAttempts($this->key($ipHash), $this->perHour());
    }

    public function hit(string $ipHash): void
    {
        RateLimiter::hit($this->key($ipHash), 3600);
    }

    private function perHour(): int
    {
        return (int) config('fatwarag.limits.hourly_shares_per_visitor', self::PER_HOUR);
    }

    private function key(string $ipHash): string
    {
        return "shares:{$ipHash}";
    }
}
