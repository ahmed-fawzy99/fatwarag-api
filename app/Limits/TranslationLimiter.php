<?php

namespace App\Limits;

use App\Models\Setting;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Caps how many first-open translations (the ones that call the AI) one visitor can start per hour, so nobody can loop over
 * fatwa ids and spend the shared monthly budget. Same pattern as the question limiter: increment first, atomically, and
 * roll back when over the limit; visitors are bucketed (IPv6 by /64) and hashed.
 */
class TranslationLimiter
{
    private const INCREMENT_SCRIPT = <<<'LUA'
        local value = redis.call('INCR', KEYS[1])
        if redis.call('TTL', KEYS[1]) < 0 then
            redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        return value
        LUA;

    /** Counts one translation for the visitor; false when they are over their hourly limit. */
    public function attempt(string $ip): bool
    {
        $key = 'translate:hourly:'.hash('sha256', QuestionLimiter::bucket($ip)).':'.now()->utc()->format('Y-m-d-H');
        $limit = (int) Setting::get('hourly_translations_per_visitor', config('fatwarag.limits.hourly_translations_per_visitor', 10));
        $store = Cache::store(config('fatwarag.ask.cache_store'));

        if ($this->increment($store, $key, 2 * 3600) > $limit) {
            $store->decrement($key);

            return false;
        }

        return true;
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
}
