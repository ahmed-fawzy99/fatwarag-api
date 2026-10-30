<?php

namespace App\Importers;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One import per source at a time (the schedule, a manual run and a collector's refresh share it).
 *
 * - The lock lives 10 minutes; the running import extends it about once a minute (`heartbeat()`, called from the
 *   import loop). If the extension finds the lock taken by someone else, the import stops (ImportLockLost).
 * - The owner token is «host|boot id|pid|random». A lock left by a process that died is taken over at once, but only
 *   when its host name and boot id match ours (same machine or container, same boot) and that pid no longer exists.
 *   Otherwise (another container, a reboot, no /proc) we can't judge the pid, so we wait for the 10 minutes to pass.
 * - On Redis every step is one atomic command or Lua script: acquire (SET NX EX), take over (replace only if the value
 *   is still the dead owner's token), extend and release (only if the value is ours). Other cache stores (tests, a
 *   single process) use the same steps without that guarantee. (`eval` below is Redis EVAL of the fixed Lua scripts
 *   in this class; no outside input is ever executed.)
 */
final class ImportLock
{
    public const TTL = 600;

    private const BEAT_EVERY = 60;

    private const REPLACE_IF = "if redis.call('get', KEYS[1]) == ARGV[1] then redis.call('set', KEYS[1], ARGV[2], 'EX', ARGV[3]) return 1 end return 0";

    private const EXTEND_IF = "if redis.call('get', KEYS[1]) == ARGV[1] then redis.call('expire', KEYS[1], ARGV[2]) return 1 end return 0";

    private const DELETE_IF = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0";

    private const ADD = "if redis.call('set', KEYS[1], ARGV[1], 'NX', 'EX', ARGV[2]) then return 1 end return 0";

    /** @var array<string, array{owner: string, beat: int}> locks this process holds, by source */
    private static array $held = [];

    private static ?string $token = null;

    /**
     * Runs the callback holding the source's lock. Returns false, without running it, when a live import holds it.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|false
     *
     * @throws ImportLockLost when another process took the lock while the callback ran
     */
    public static function run(string $source, callable $callback): mixed
    {
        $owner = self::token();

        if (! self::acquire($source, $owner)) {
            return false;
        }

        self::$held[$source] = ['owner' => $owner, 'beat' => now()->getTimestamp()];

        try {
            return $callback();
        } finally {
            unset(self::$held[$source]);
            self::deleteIf(self::key($source), $owner);
        }
    }

    /**
     * Extends the lock about once a minute while an import runs. A no-op when this process doesn't hold the lock
     * (an importer run directly, in tests or by hand).
     *
     * @throws ImportLockLost
     */
    public static function heartbeat(string $source): void
    {
        $held = self::$held[$source] ?? null;

        if ($held === null || now()->getTimestamp() - $held['beat'] < self::BEAT_EVERY) {
            return;
        }

        if (! self::extendIf(self::key($source), $held['owner'])) {
            unset(self::$held[$source]);

            throw new ImportLockLost("The {$source} import lock was taken by another process; this import stops.");
        }

        self::$held[$source]['beat'] = now()->getTimestamp();
    }

    /**
     * Replaces a dead owner's lock with ours, only if it still holds that owner's token (so two processes that both
     * found it dead can't both win).
     */
    public static function takeOver(string $source, string $deadOwner, string $owner): bool
    {
        $key = self::key($source);

        if (self::redis() !== null) {
            return (bool) self::redis()->connection()->eval(self::REPLACE_IF, 1, self::redis()->getPrefix().$key, $deadOwner, $owner, self::TTL);
        }

        if (Cache::get($key) !== $deadOwner) {
            return false;
        }
        Cache::put($key, $owner, self::TTL);

        return true;
    }

    /**
     * Whether an owner token names a process on this machine and boot that no longer exists.
     */
    public static function isDead(string $owner): bool
    {
        [$host, $boot, $pid] = array_pad(explode('|', $owner), 3, '');

        if ($host !== gethostname() || $boot === '' || $boot !== self::bootId() || ! ctype_digit($pid)) {
            return false;
        }

        return ! file_exists("/proc/{$pid}");
    }

    public static function token(): string
    {
        return self::$token ??= implode('|', [gethostname(), self::bootId(), getmypid(), Str::random(8)]);
    }

    private static function acquire(string $source, string $owner): bool
    {
        $key = self::key($source);
        $added = self::redis() !== null
            ? (bool) self::redis()->connection()->eval(self::ADD, 1, self::redis()->getPrefix().$key, $owner, self::TTL)
            : Cache::add($key, $owner, self::TTL);

        if ($added) {
            return true;
        }

        $holder = self::redis() !== null ? self::redis()->connection()->get(self::redis()->getPrefix().$key) : Cache::get($key);

        return is_string($holder) && self::isDead($holder) && self::takeOver($source, $holder, $owner);
    }

    private static function extendIf(string $key, string $owner): bool
    {
        if (self::redis() !== null) {
            return (bool) self::redis()->connection()->eval(self::EXTEND_IF, 1, self::redis()->getPrefix().$key, $owner, self::TTL);
        }

        if (Cache::get($key) !== $owner) {
            return false;
        }
        Cache::put($key, $owner, self::TTL);

        return true;
    }

    private static function deleteIf(string $key, string $owner): void
    {
        if (self::redis() !== null) {
            self::redis()->connection()->eval(self::DELETE_IF, 1, self::redis()->getPrefix().$key, $owner);
        } elseif (Cache::get($key) === $owner) {
            Cache::forget($key);
        }
    }

    private static function redis(): ?RedisStore
    {
        $store = Cache::getStore();

        return $store instanceof RedisStore ? $store : null;
    }

    /**
     * This boot's id (Linux). Containers share the host's boot id but have their own host name, so the pair names one
     * process namespace. Empty when unknown: pids are then never judged.
     */
    private static function bootId(): string
    {
        return trim((string) @file_get_contents('/proc/sys/kernel/random/boot_id'));
    }

    private static function key(string $source): string
    {
        return "import-lock:{$source}";
    }
}
