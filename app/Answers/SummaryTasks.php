<?php

namespace App\Answers;

use App\Services\AiSpend;
use Closure;
use Illuminate\Support\Facades\Log;
use Laravel\SerializableClosure\SerializableClosure;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Throwable;

use function Illuminate\Support\php_binary;

/**
 * Runs a summary's judge and translation calls side by side while the summary is still being written.
 *
 * Each step runs in its own `php artisan answers:summary-task` process. A couple of processes are started (and boot)
 * before any step exists, so a step starts at once; a waiting process that got no step within IDLE_SECONDS is
 * stopped. Processes are bounded four ways: at most MAX_PER_SUMMARY alive for one summary, a server-wide number of
 * processes of any kind, waiting or working, and, for working ones, server-wide and per visitor (ProcessSlots, Redis
 * leases renewed on every poll). A step that finds no slot waits in a
 * short first-come queue, then runs right here instead. Steps never block the caller: `poll()` collects the finished
 * ones. If Redis can't be reached, a process can't be started, or under tests (where the AI fakes live in this
 * process), steps run right here, one after another.
 *
 * A step whose process is stopped before it reports (the reader left, the draft was given up, a timeout) may already
 * have been billed, so its cost is recorded from the estimate given with it, never left out of the budget.
 */
class SummaryTasks
{
    /** Processes kept booted and waiting for a step. */
    private const WARM = 2;

    /** Processes one summary may have alive at once, waiting or working. */
    public const MAX_PER_SUMMARY = 6;

    /** A step's process is stopped after this long, and the step counts as failed. */
    public const TIMEOUT_SECONDS = 120;

    /** A waiting process that got no step for this long is stopped (it never holds a slot, or outlives its timeout). */
    public const IDLE_SECONDS = 45;

    private bool $inline;

    private string $client = 'unknown';

    /** Whether this summary will run steps (warmed or started): only then do waiting processes boot. */
    private bool $stepsExpected = false;

    /** @var list<array{process: Process, input: InputStream, since: float, lease: string}> */
    private array $idle = [];

    /** @var array<string, array{process: Process, input: InputStream, since: float, token: string, task: Closure, estimate: array<string, mixed>|null}> */
    private array $running = [];

    /** @var array<string, array{task: Closure, since: float, ticket: string, estimate: array<string, mixed>|null}> */
    private array $queued = [];

    /** @var array<string, array{ok: bool, result: mixed}> */
    private array $finished = [];

    /**
     * @param  list<string>|null  $command  the step process's command (default: this app's artisan)
     * @param  float|null  $queueSeconds  how long a step waits for a slot before it runs here (`fatwarag.summary.queue_seconds`)
     */
    public function __construct(
        ?string $driver = null,
        private ?array $command = null,
        private ?ProcessSlots $slots = null,
        private ?float $queueSeconds = null,
    ) {
        $driver ??= app()->runningUnitTests() ? 'sync' : (string) config('fatwarag.summary.concurrency_driver', 'process');
        $this->inline = $driver !== 'process';
        $this->slots ??= app(ProcessSlots::class);
        $this->queueSeconds ??= (float) config('fatwarag.summary.queue_seconds', 3);
    }

    /**
     * The visitor the steps are for (their IP), for the per-visitor cap.
     */
    public function forClient(string $client): self
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Starts the waiting processes, so they boot while the summary is being written.
     */
    public function warm(): void
    {
        $this->stepsExpected = true;

        while (! $this->inline && count($this->idle) < self::WARM && $this->processes() < self::MAX_PER_SUMMARY) {
            if (! $this->spawn()) {
                return;
            }
        }
    }

    /**
     * Starts a step, or queues it until a slot is free; its result arrives through `poll()` under the same key.
     *
     * @param  array{purpose: string, model: string, price: string, input: int, output: int}|null  $estimate  its cost if its process is stopped before it reports
     */
    public function start(string $key, Closure $task, ?array $estimate = null): void
    {
        if ($this->inline) {
            $this->runHere($key, $task);

            return;
        }

        $this->stepsExpected = true;
        $this->queued[$key] = ['task' => $task, 'since' => microtime(true), 'ticket' => bin2hex(random_bytes(12)), 'estimate' => $estimate];
        $this->dispatch();
    }

    /**
     * The steps that finished since the last call: `ok` false when the step failed or its process did.
     *
     * @return array<string, array{ok: bool, result: mixed}>
     */
    public function poll(): array
    {
        foreach ($this->running as $key => $worker) {
            $process = $worker['process'];

            try {
                $process->checkTimeout();

                if ($process->isRunning()) {
                    $this->slots->renew($worker['token'], $this->client);
                    $this->slots->renewSpawned($worker['lease']);

                    continue;
                }

                $output = json_decode($process->getOutput(), true);

                if (! is_array($output)) {
                    // The process itself failed (not the step): run the step here instead.
                    Log::warning('A summary step process failed; the step ran in the request process', ['exit' => $process->getExitCode()]);
                    $this->runHere($key, $worker['task']);
                } else {
                    $this->finished[$key] = ($output['ok'] ?? false) === true
                        ? ['ok' => true, 'result' => unserialize((string) base64_decode((string) ($output['result'] ?? ''), true))]
                        : ['ok' => false, 'result' => null];
                }
            } catch (Throwable $exception) {
                Log::warning('A summary step failed', ['error' => $exception::class]);
                $this->halt($worker);
                $this->finished[$key] = ['ok' => false, 'result' => null];
                unset($this->running[$key]);

                continue;
            }

            $this->release($worker);
            $this->despawn($worker['lease']);
            unset($this->running[$key]);
        }

        $this->retireIdle();
        $this->dispatch();

        if ($this->stepsExpected) {
            $this->warm();
        }

        $finished = $this->finished;
        $this->finished = [];

        return $finished;
    }

    public function busy(): bool
    {
        return $this->running !== [] || $this->queued !== [] || $this->finished !== [];
    }

    /**
     * How many step processes this summary has alive, waiting or working.
     */
    public function processes(): int
    {
        return count($this->idle) + count($this->running);
    }

    /**
     * Waits a moment for running steps.
     */
    public function pause(float $seconds = 0.02): void
    {
        if ($this->running !== [] || $this->queued !== []) {
            usleep((int) ($seconds * 1_000_000));
        }
    }

    /**
     * Abandons every step not yet finished (a draft given up on, or a reader who left): running processes are
     * stopped (their estimated cost recorded), queued steps dropped and finished results discarded. Waiting
     * processes stay for the next steps.
     */
    public function cancel(): void
    {
        foreach ($this->running as $worker) {
            $this->halt($worker);
        }

        foreach ($this->queued as $entry) {
            $this->leave($entry['ticket']);
        }

        $this->running = [];
        $this->queued = [];
        $this->finished = [];
    }

    /**
     * Stops every process still running or waiting.
     */
    public function stop(): void
    {
        $this->cancel();

        foreach ($this->idle as $worker) {
            $this->kill($worker);
        }

        $this->idle = [];
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Hands queued steps, oldest first, to waiting processes once a slot is taken for them; a step that waited longer
     * than the queue allows runs here instead.
     */
    private function dispatch(): void
    {
        foreach ($this->queued as $key => $entry) {
            if ($this->inline) {
                $this->leave($entry['ticket']);
                unset($this->queued[$key]);
                $this->runHere($key, $entry['task']);

                continue;
            }

            if (microtime(true) - $entry['since'] > $this->queueSeconds) {
                // Every slot is busy, server-wide or for this visitor: do the step here rather than wait longer.
                Log::info('A summary step ran in the request process: no free slot in time');
                $this->leave($entry['ticket']);
                unset($this->queued[$key]);
                $this->runHere($key, $entry['task']);

                continue;
            }

            if ($this->idle === [] && ! $this->spawn()) {
                continue;
            }

            $token = bin2hex(random_bytes(12));

            try {
                if (! $this->slots->acquire($token, $entry['ticket'], $this->client)) {
                    continue; // queued: older steps, or this visitor's other steps, go first
                }
            } catch (Throwable $exception) {
                Log::warning('Summary steps run one after another: the process slots are unavailable', ['error' => $exception::class]);
                $this->inline = true;
                unset($this->queued[$key]);
                $this->runHere($key, $entry['task']);

                continue;
            }

            $worker = array_shift($this->idle);
            unset($this->queued[$key]);

            try {
                $worker['input']->write(base64_encode(serialize(new SerializableClosure($entry['task']))));
                $worker['input']->close();
                $worker['process']->isRunning(); // pushes the input to the process
                $this->running[$key] = [...$worker, 'token' => $token, 'task' => $entry['task'], 'estimate' => $entry['estimate']];
            } catch (Throwable $exception) {
                Log::warning('A summary step ran in the request process', ['error' => $exception::class]);
                $this->kill($worker);
                $this->release(['token' => $token]);
                $this->runHere($key, $entry['task']);
            }
        }
    }

    /**
     * Stops waiting processes that got no step in time, before they could outlive their own timeout.
     */
    private function retireIdle(): void
    {
        foreach ($this->idle as $i => $worker) {
            if (microtime(true) - $worker['since'] > self::IDLE_SECONDS || ! $worker['process']->isRunning()) {
                $this->kill($worker);
                unset($this->idle[$i]);

                continue;
            }

            try {
                $this->slots->renewSpawned($worker['lease']);
            } catch (Throwable) {
                // the lease expires on its own
            }
        }

        $this->idle = array_values($this->idle);
    }

    private function spawn(): bool
    {
        if ($this->inline || $this->processes() >= self::MAX_PER_SUMMARY) {
            return false;
        }

        $lease = bin2hex(random_bytes(12));

        try {
            if (! $this->slots->spawn($lease)) {
                return false; // the server has as many step processes as it allows: the step waits, then runs here
            }
        } catch (Throwable $exception) {
            Log::warning('Summary steps run one after another: the process slots are unavailable', ['error' => $exception::class]);
            $this->inline = true;

            return false;
        }

        try {
            $input = new InputStream;
            $process = new Process($this->command ?? [php_binary(), base_path('artisan'), 'answers:summary-task'], base_path(), null, $input, self::TIMEOUT_SECONDS);
            $process->start();
            $this->idle[] = ['process' => $process, 'input' => $input, 'since' => microtime(true), 'lease' => $lease];

            return true;
        } catch (Throwable $exception) {
            Log::warning('Summary steps run one after another: a process could not be started', ['error' => $exception::class]);
            $this->despawn($lease);
            $this->inline = true;

            return false;
        }
    }

    /**
     * Stops a working process before it reported: its slot is freed and its estimated cost recorded (the call may
     * already have been billed; counting it twice is safer than not at all).
     *
     * @param  array{process: Process, input: InputStream, token: string, estimate?: array<string, mixed>|null}  $worker
     */
    private function halt(array $worker): void
    {
        $this->kill($worker);
        $this->release($worker);

        $estimate = $worker['estimate'] ?? null;

        if (is_array($estimate)) {
            try {
                app(AiSpend::class)->record($estimate['purpose'], $estimate['model'], $estimate['input'], $estimate['output'], AiSpend::estimate($estimate['price'], $estimate['input'], $estimate['output']));
            } catch (Throwable $exception) {
                Log::warning('A stopped summary step\'s cost could not be recorded', ['error' => $exception::class]);
            }
        }
    }

    /**
     * @param  array{process: Process, input: InputStream, lease?: string}  $worker
     */
    private function kill(array $worker): void
    {
        try {
            $worker['input']->close();
            $worker['process']->stop(0);
        } catch (Throwable) {
            // already gone
        }

        $this->despawn($worker['lease'] ?? '');
    }

    private function despawn(string $lease): void
    {
        if ($lease === '') {
            return;
        }

        try {
            $this->slots->despawn($lease);
        } catch (Throwable) {
            // the lease expires on its own
        }
    }

    /**
     * @param  array{token: string}  $worker
     */
    private function release(array $worker): void
    {
        try {
            $this->slots->release($worker['token'], $this->client);
        } catch (Throwable) {
            // the lease expires on its own
        }
    }

    private function leave(string $ticket): void
    {
        try {
            $this->slots->leave($ticket);
        } catch (Throwable) {
            // the ticket expires on its own
        }
    }

    private function runHere(string $key, Closure $task): void
    {
        try {
            $this->finished[$key] = ['ok' => true, 'result' => $task()];
        } catch (Throwable $exception) {
            Log::warning('A summary step failed', ['error' => $exception::class]);
            $this->finished[$key] = ['ok' => false, 'result' => null];
        }
    }
}
