<?php

namespace App\Limits;

use App\Models\Setting;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The daily cap on questions overall and the hourly limit per visitor, counted in Redis.
 *
 * Counters are incremented first, atomically, and the attempt is refused (and rolled back) when the new value is over
 * the limit, so concurrent requests can't slip past a cap. Visitor IPs are bucketed (IPv6 by its /64 prefix, which one
 * subscriber usually controls entirely) and hashed before they become part of a key, so no plain IP is kept.
 */
class QuestionLimiter
{
    public const DAILY_CAP_REACHED = 'daily_cap_reached';

    public const RATE_LIMITED = 'rate_limited';

    /**
     * INCR that sets the TTL when the key is new (or has lost its TTL), in one atomic step.
     */
    private const INCREMENT_SCRIPT = <<<'LUA'
        local value = redis.call('INCR', KEYS[1])
        if redis.call('TTL', KEYS[1]) < 0 then
            redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        return value
        LUA;

    /**
     * Counts one question for the visitor, or returns why it isn't allowed (`daily_cap_reached` or `rate_limited`).
     */
    public function attempt(string $ip): ?string
    {
        $now = now()->utc();
        $dailyKey = 'ask:daily:'.$now->format('Y-m-d');
        $hourlyKey = 'ask:hourly:'.hash('sha256', self::bucket($ip)).':'.$now->format('Y-m-d-H');

        $store = $this->store();
        $dailyCap = (int) Setting::get('daily_questions', config('fatwarag.limits.daily_questions'));
        $hourlyLimit = (int) Setting::get('hourly_questions_per_visitor', config('fatwarag.limits.hourly_questions_per_visitor'));

        if ($this->increment($store, $dailyKey, 2 * 24 * 3600) > $dailyCap) {
            $store->decrement($dailyKey);

            return self::DAILY_CAP_REACHED;
        }

        if ($this->increment($store, $hourlyKey, 2 * 3600) > $hourlyLimit) {
            $store->decrement($hourlyKey);
            $store->decrement($dailyKey);

            return self::RATE_LIMITED;
        }

        return null;
    }

    /**
     * The identity a visitor is limited by: the IPv4 address, or the /64 prefix of an IPv6 address
     * (IPv4-mapped IPv6 addresses count as their IPv4 address).
     */
    public static function bucket(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return $ip;
        }

        if (strlen($packed) === 4) {
            return inet_ntop($packed);
        }

        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }

    private function increment(Repository $store, string $key, int $ttlSeconds): int
    {
        $underlying = $store->getStore();

        if ($underlying instanceof RedisStore) {
            return (int) $underlying->connection()->eval(self::INCREMENT_SCRIPT, 1, $underlying->getPrefix().$key, $ttlSeconds);
        }

        $store->add($key, 0, $ttlSeconds);

        return (int) $store->increment($key);
    }

    private function store(): Repository
    {
        return Cache::store(config('fatwarag.ask.cache_store'));
    }
}
