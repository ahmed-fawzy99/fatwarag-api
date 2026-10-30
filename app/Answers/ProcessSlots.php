<?php

namespace App\Answers;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Server-wide caps on summary step processes (SummaryTasks): at most `max_spawned` processes exist at once, waiting or
 * working; of those, at most `max_processes` work on a step at once in all, and `max_processes_per_client` for one
 * visitor, so a few clients can't take every slot.
 *
 * Only working processes hold a slot; processes waiting for a step hold none. Each slot is a lease in a Redis sorted
 * set, renewed while its step runs; a lease left by a process that died expires after LEASE_SECONDS, so a crash
 * never leaks a slot for long. When the server is full, a step takes a ticket in a short first-come queue: the
 * oldest tickets get the next free slots, so no summary is starved by newer ones.
 *
 * The scripts below are fixed Lua run by Redis EVAL (atomic check-and-take); no outside input becomes code.
 */
class ProcessSlots
{
    private const KEY = 'answers:summary:process-slots';

    /** Longer than a step process may live (SummaryTasks::TIMEOUT_SECONDS); renewed while it runs. */
    public const LEASE_SECONDS = 150;

    /** A ticket older than this leaves the queue (its step has long run here instead, or its summary went away). */
    private const TICKET_SECONDS = 15;

    private const ACQUIRE = <<<'LUA'
        local now = tonumber(ARGV[1])
        redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now - tonumber(ARGV[3]))
        redis.call('ZREMRANGEBYSCORE', KEYS[3], '-inf', now - tonumber(ARGV[3]))
        redis.call('ZREMRANGEBYSCORE', KEYS[2], '-inf', now - tonumber(ARGV[7]))
        if redis.call('ZCARD', KEYS[3]) >= tonumber(ARGV[6]) then
            return 0
        end
        local free = tonumber(ARGV[2]) - redis.call('ZCARD', KEYS[1])
        local rank = redis.call('ZRANK', KEYS[2], ARGV[5])
        if free <= 0 or (rank and rank >= free) or ((not rank) and redis.call('ZCARD', KEYS[2]) >= free) then
            redis.call('ZADD', KEYS[2], 'NX', now, ARGV[5])
            redis.call('EXPIRE', KEYS[2], tonumber(ARGV[7]))
            return 0
        end
        redis.call('ZREM', KEYS[2], ARGV[5])
        redis.call('ZADD', KEYS[1], now, ARGV[4])
        redis.call('EXPIRE', KEYS[1], tonumber(ARGV[3]))
        redis.call('ZADD', KEYS[3], now, ARGV[4])
        redis.call('EXPIRE', KEYS[3], tonumber(ARGV[3]))
        return 1
        LUA;

    private const RELEASE = <<<'LUA'
        redis.call('ZREM', KEYS[1], ARGV[1])
        redis.call('ZREM', KEYS[2], ARGV[1])
        return 1
        LUA;

    private const RENEW = <<<'LUA'
        redis.call('ZADD', KEYS[1], ARGV[1], ARGV[2])
        redis.call('ZADD', KEYS[2], ARGV[1], ARGV[2])
        redis.call('EXPIRE', KEYS[1], tonumber(ARGV[3]))
        redis.call('EXPIRE', KEYS[2], tonumber(ARGV[3]))
        return 1
        LUA;

    private const SPAWN = <<<'LUA'
        local now = tonumber(ARGV[1])
        redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now - tonumber(ARGV[3]))
        if redis.call('ZCARD', KEYS[1]) >= tonumber(ARGV[2]) then
            return 0
        end
        redis.call('ZADD', KEYS[1], now, ARGV[4])
        redis.call('EXPIRE', KEYS[1], tonumber(ARGV[3]))
        return 1
        LUA;

    private const ONE = <<<'LUA'
        if ARGV[1] == 'renew' then
            redis.call('ZADD', KEYS[1], ARGV[3], ARGV[2])
            redis.call('EXPIRE', KEYS[1], tonumber(ARGV[4]))
        else
            redis.call('ZREM', KEYS[1], ARGV[2])
        end
        return 1
        LUA;

    private const LEAVE = <<<'LUA'
        return redis.call('ZREM', KEYS[1], ARGV[1])
        LUA;

    /**
     * Takes a slot for a step about to run in its own process; false when the server or this client is full (the
     * ticket then holds the step's place in the queue). Throws when Redis can't be reached.
     */
    public function acquire(string $token, string $ticket, string $client): bool
    {
        return (int) $this->redis()->eval(self::ACQUIRE, 3, self::KEY, self::KEY.':queue', $this->clientKey($client),
            (string) microtime(true), (string) $this->limit(), (string) self::LEASE_SECONDS, $token, $ticket,
            (string) $this->clientLimit(), (string) self::TICKET_SECONDS) === 1;
    }

    /**
     * Keeps a running step's slot (called on every poll, only while its process is alive). A lease that expired
     * meanwhile is taken again, so a live process is always counted.
     */
    public function renew(string $token, string $client): void
    {
        $this->redis()->eval(self::RENEW, 2, self::KEY, $this->clientKey($client), (string) microtime(true), $token, (string) self::LEASE_SECONDS);
    }

    public function release(string $token, string $client): void
    {
        $this->redis()->eval(self::RELEASE, 2, self::KEY, $this->clientKey($client), $token);
    }

    /**
     * Leaves the queue (the step ran here instead, or was abandoned).
     */
    public function leave(string $ticket): void
    {
        $this->redis()->eval(self::LEAVE, 1, self::KEY.':queue', $ticket);
    }

    /**
     * Takes a lease for a new process of any kind, waiting or working: at most `max_spawned` processes exist
     * server-wide. False when none is free. Throws when Redis can't be reached.
     */
    public function spawn(string $process): bool
    {
        return (int) $this->redis()->eval(self::SPAWN, 1, self::KEY.':spawned', (string) microtime(true), (string) $this->spawnLimit(), (string) self::LEASE_SECONDS, $process) === 1;
    }

    /**
     * Keeps a living process's lease (called on every poll, only while it is alive); one that expired meanwhile is
     * taken again, so a live process is always counted.
     */
    public function renewSpawned(string $process): void
    {
        $this->redis()->eval(self::ONE, 1, self::KEY.':spawned', 'renew', $process, (string) microtime(true), (string) self::LEASE_SECONDS);
    }

    /**
     * Frees a process's lease once it has exited or been stopped.
     */
    public function despawn(string $process): void
    {
        $this->redis()->eval(self::ONE, 1, self::KEY.':spawned', 'release', $process);
    }

    public function spawnLimit(): int
    {
        return max(1, (int) config('fatwarag.summary.max_spawned', 24));
    }

    public function limit(): int
    {
        return max(1, (int) config('fatwarag.summary.max_processes', 18));
    }

    public function clientLimit(): int
    {
        return max(1, (int) config('fatwarag.summary.max_processes_per_client', 6));
    }

    private function clientKey(string $client): string
    {
        return self::KEY.':client:'.hash('sha256', $client);
    }

    private function redis(): Connection
    {
        return Redis::connection(config('fatwarag.summary.process_slots_connection', 'default'));
    }
}
