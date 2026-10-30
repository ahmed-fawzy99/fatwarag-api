<?php

namespace App\Collectors;

use App\Importers\Importer;
use App\Importers\ImportLock;
use App\Importers\ImportResult;
use App\Models\CollectionTask;
use App\Models\CollectorState;
use App\Models\Fatwa;
use App\Models\Source;
use App\Services\Alerts;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The 3-day refresh for one source: discover fatwas we don't have, queue them in `collection_tasks`, fetch each into
 * the raw store (same layout as the harvesters), then run the source's importer. The queue and the collector's cursor
 * make it resumable: a run stopped by a 403 picks up where it stopped. It never runs while the source's first harvest
 * is still going.
 */
abstract class Collector
{
    private const MAX_ATTEMPTS = 3;

    protected Source $source;

    protected CollectorState $state;

    public function __construct(protected Alerts $alerts) {}

    abstract public function sourceKey(): string;

    /**
     * Queues tasks for fatwas the store doesn't have yet. May use and update `$this->state->cursor`.
     */
    abstract protected function discover(): void;

    /**
     * Fetches one queued page into the raw store. Returns false when the page holds no fatwa (soft 404).
     */
    abstract protected function fetch(CollectionTask $task): bool;

    abstract protected function importer(): Importer;

    public function refresh(): CollectorState
    {
        $this->source = Source::query()->where('key', $this->sourceKey())->firstOrFail();
        $this->state = CollectorState::query()->firstOrCreate(['source_id' => $this->source->id]);

        if ($this->harvestIsRunning()) {
            $this->state->update(['last_checked_at' => now()]);

            return $this->state;
        }

        // A task still «running» was cut off by a crash or a kill: it goes back in the queue.
        $interrupted = CollectionTask::query()->where('source_id', $this->source->id)->where('status', 'running')->update(['status' => 'pending']);
        $wasInterrupted = $this->state->getRawOriginal('status') === 'running';

        $this->state->update([
            'status' => 'running',
            'stop_reason' => $wasInterrupted || $interrupted > 0 ? "Resumed after an interrupted run ({$interrupted} unfinished pages requeued)." : null,
            'last_started_at' => now(),
            'last_checked_at' => now(),
        ]);
        $fetched = 0;

        try {
            $this->discover();
            $fetched = $this->work();
            $this->state->update(['status' => 'done', 'stop_reason' => null, 'last_finished_at' => now()]);
            $this->alerts->resolve($this->fingerprint());
        } catch (Throwable $exception) {
            $this->stop($exception);
        }

        if ($fetched > 0) {
            $this->import();
        }

        return $this->state->refresh();
    }

    /**
     * @return int pages saved
     */
    private function work(): int
    {
        $saved = 0;

        while (($task = $this->nextTask()) !== null) {
            $task->update(['status' => 'running', 'attempts' => $task->attempts + 1]);
            $this->state->update(['last_checked_at' => now()]);

            try {
                $hasFatwa = $this->fetch($task);
            } catch (CollectorBlocked $blocked) {
                $task->update(['status' => 'pending', 'attempts' => $task->attempts - 1]);

                throw $blocked;
            } catch (Throwable $exception) {
                $task->update([
                    'status' => $task->attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
                    'last_error' => mb_substr($exception->getMessage(), 0, 500),
                    'available_at' => now()->addMinutes(10),
                ]);

                continue;
            }

            $task->update(['status' => $hasFatwa ? 'done' : 'skipped', 'done_at' => now(), 'last_error' => null]);

            if ($hasFatwa) {
                $saved++;
                $this->state->increment('items_collected');
            }
        }

        return $saved;
    }

    private function nextTask(): ?CollectionTask
    {
        return CollectionTask::query()
            ->where('source_id', $this->source->id)
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderByDesc('priority')
            ->orderBy('id')
            ->first();
    }

    private function stop(Throwable $exception): void
    {
        $reason = $exception instanceof CollectorBlocked ? $exception->getMessage() : 'Error: '.mb_substr($exception->getMessage(), 0, 300);
        $this->state->update(['status' => 'stopped', 'stop_reason' => $reason, 'last_finished_at' => now()]);

        $this->alerts->raise(
            'collector',
            $this->fingerprint(),
            "The {$this->sourceKey()} refresh stopped: {$reason}",
            ['pending' => CollectionTask::query()->where('source_id', $this->source->id)->where('status', 'pending')->count()],
            "Check {$this->source->base_url} by hand. The next run (or: php artisan fatwas:refresh {$this->sourceKey()}) resumes where this one stopped.",
        );
    }

    private function import(): ?ImportResult
    {
        try {
            // If the scheduled import holds the lock, it (or the next one, within 10 minutes) imports these pages.
            $result = ImportLock::run($this->sourceKey(), fn () => $this->importer()->import());

            return $result === false ? null : $result;
        } catch (Throwable $exception) {
            $this->alerts->raise('import', "import:{$this->sourceKey()}", "Importing the {$this->sourceKey()} refresh failed: ".mb_substr($exception->getMessage(), 0, 300), [], "Run: php artisan fatwas:import {$this->sourceKey()}");

            return null;
        }
    }

    private function fingerprint(): string
    {
        return "collector:{$this->sourceKey()}";
    }

    /**
     * The first harvest writes progress.json; while it is running (and recently updated) the refresh waits.
     */
    private function harvestIsRunning(): bool
    {
        $progress = json_decode(@file_get_contents($this->rawDir('progress.json')) ?: '{}', true);

        if (($progress['state'] ?? null) !== 'running' || ! isset($progress['updated_at'])) {
            return false;
        }

        try {
            return Carbon::parse($progress['updated_at'])->gt(now()->subMinutes(30));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A polite GET: honest user agent, the source's safe rate, and a stop on 403/429 or a challenge page.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws CollectorBlocked
     * @throws ConnectionException
     */
    protected function get(string $url, array $options = []): Response
    {
        $delay = (int) config("fatwarag.collectors.delay_ms.{$this->sourceKey()}", 500);
        if ($delay > 0) {
            usleep($delay * 1000);
        }

        $response = Http::withUserAgent((string) config('fatwarag.collectors.user_agent'))
            ->timeout((int) config('fatwarag.collectors.timeout', 30))
            ->withOptions($options)
            ->get($url);

        if (in_array($response->status(), [403, 429], true)) {
            throw new CollectorBlocked("HTTP {$response->status()} on {$url}");
        }

        if (preg_match('/challenge-platform|cf-chl|Just a moment\.\.\.|Attention Required/i', substr($response->body(), 0, 20000))) {
            throw new CollectorBlocked("Challenge page on {$url}");
        }

        return $response;
    }

    /**
     * Queues a page to fetch unless it is already queued or done.
     *
     * @param  array<string, mixed>|null  $payload
     */
    protected function queue(string $kind, string $externalId, string $url, ?array $payload = null, int $priority = 0): void
    {
        CollectionTask::query()->firstOrCreate(
            ['source_id' => $this->source->id, 'kind' => $kind, 'external_id' => $externalId],
            ['url' => $url, 'payload' => $payload, 'priority' => $priority, 'status' => 'pending'],
        );
    }

    /**
     * Source fatwa ids already imported.
     *
     * @return array<string, true>
     */
    protected function knownIds(): array
    {
        return array_fill_keys(Fatwa::query()->where('source_id', $this->source->id)->pluck('source_fatwa_id')->all(), true);
    }

    protected function rawDir(string $path = ''): string
    {
        return rtrim(config('fatwarag.raw_path'), '/').'/'.$this->sourceKey().($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    /**
     * Saves a page gzipped into the raw store (written to a temp file first, so an importer never reads half a page).
     */
    protected function save(string $relativePath, string $body): void
    {
        $path = $this->rawDir($relativePath);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents("{$path}.tmp", gzencode($body));
        rename("{$path}.tmp", $path);
    }

    /**
     * Appends a line to the store's manifest, in the harvesters' format.
     *
     * @param  array<string, mixed>  $entry
     */
    protected function manifest(array $entry): void
    {
        $line = json_encode($entry + ['fetched_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'via_refresh' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->rawDir('manifest.jsonl'), $line."\n", FILE_APPEND | LOCK_EX);
    }
}
