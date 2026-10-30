<?php

namespace App\Limits;

use App\Http\Controllers\Api\SummaryStreamController;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Caps how many summaries one visitor may have running at once (`fatwarag.summary.max_streams_per_client`). Each one
 * holds an Octane worker for its whole run, so a few visitors keeping many open would stall every other request.
 *
 * A counter per visitor (bucketed and hashed as the other per-visitor limits are), taken before the summary starts and
 * given back when it ends. Its TTL outlasts the longest summary and is renewed on each take, so a count a dying worker
 * never gave back expires on its own.
 */
class SummaryStreamLimiter
{
    private const TTL_SECONDS = SummaryStreamController::MAX_SECONDS + 60;

    private const INCREMENT_SCRIPT = <<<'LUA'
        local value = redis.call('INCR', KEYS[1])
        redis.call('EXPIRE', KEYS[1], ARGV[1])
        return value
        LUA;

    private const DECREMENT_SCRIPT = <<<'LUA'
        local value = redis.call('DECR', KEYS[1])
        if value <= 0 then
            redis.call('DEL', KEYS[1])
        end
        return value
        LUA;

    /** Takes one running-summary slot for the visitor; false when they already have the most allowed. */
    public function acquire(string $ip): bool
    {
        $key = $this->key($ip);
        $limit = max(1, (int) config('fatwarag.summary.max_streams_per_client', 2));
        $store = $this->store();
        $underlying = $store->getStore();

        if ($underlying instanceof RedisStore) {
            $value = (int) $underlying->connection()->eval(self::INCREMENT_SCRIPT, 1, $underlying->getPrefix().$key, self::TTL_SECONDS);
        } else {
            $store->add($key, 0, self::TTL_SECONDS);
            $value = (int) $store->increment($key);
        }

        if ($value > $limit) {
            $this->release($ip);

            return false;
        }

        return true;
    }

    /** Gives the slot back once the summary has ended. */
    public function release(string $ip): void
    {
        $key = $this->key($ip);
        $store = $this->store();
        $underlying = $store->getStore();

        if ($underlying instanceof RedisStore) {
            $underlying->connection()->eval(self::DECREMENT_SCRIPT, 1, $underlying->getPrefix().$key);

            return;
        }

        if ((int) $store->decrement($key) <= 0) {
            $store->forget($key);
        }
    }

    private function key(string $ip): string
    {
        return 'summary:running:'.hash('sha256', QuestionLimiter::bucket($ip));
    }

    private function store(): Repository
    {
        return Cache::store(config('fatwarag.ask.cache_store'));
    }
}
