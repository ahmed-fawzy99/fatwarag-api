<?php

namespace Tests\Feature\Answers;

use App\Answers\ProcessSlots;
use App\Answers\SummaryTasks;
use App\Models\AiUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SummaryTasksTest extends TestCase
{
    use RefreshDatabase;

    public function test_steps_run_in_waiting_background_processes(): void
    {
        $tasks = new SummaryTasks('process', slots: $this->slots(10));
        $tasks->warm();

        $tasks->start('first', static fn (): array => ['text' => 'لا يجوز', 'pid' => getmypid()]);
        $tasks->start('second', static fn (): int => 6 * 7);

        $results = $this->collect($tasks, 2);

        $this->assertSame('لا يجوز', $results['first']['result']['text']);
        $this->assertNotSame(getmypid(), $results['first']['result']['pid']);
        $this->assertSame(['ok' => true, 'result' => 42], $results['second']);
        $tasks->stop();
    }

    /**
     * Review fix round 4: a summary that runs no step (Arabic with the claims judge off) boots no process.
     */
    public function test_a_summary_that_runs_no_step_boots_no_process(): void
    {
        $slots = $this->slots(10);
        $tasks = new SummaryTasks('process', slots: $slots);

        $this->assertSame([], $tasks->poll());
        $this->assertSame(0, $tasks->processes());
        $this->assertSame(0, $slots->spawned);
        $tasks->stop();
    }

    /**
     * Review fix round 1: at most MAX_PER_SUMMARY processes for one summary; more steps wait for a free one.
     */
    public function test_one_summary_never_has_more_than_its_cap_of_processes(): void
    {
        $slots = $this->slots(50);
        $tasks = new SummaryTasks('process', slots: $slots);
        $tasks->warm();

        foreach (range(1, 9) as $i) {
            $tasks->start("step{$i}", static function () use ($i): int {
                usleep(300_000);

                return $i;
            });
            $this->assertLessThanOrEqual(SummaryTasks::MAX_PER_SUMMARY, $tasks->processes());
        }

        $results = [];
        $deadline = microtime(true) + 60;

        while (count($results) < 9 && microtime(true) < $deadline) {
            $results += $tasks->poll();
            $this->assertLessThanOrEqual(SummaryTasks::MAX_PER_SUMMARY, $tasks->processes());
            $this->assertLessThanOrEqual(SummaryTasks::MAX_PER_SUMMARY, $slots->peak);
            $tasks->pause(0.05);
        }

        $this->assertSame(range(1, 9), array_values(array_map(fn (array $result): int => $result['result'], $this->sorted($results))));
        $tasks->stop();
        $this->assertSame(0, $slots->taken);
    }

    /**
     * Review fix round 2: when the server-wide (or this visitor's) slots are all taken, a step waits briefly in the
     * queue, then runs here instead of failing; waiting processes never hold a slot.
     */
    public function test_a_step_that_finds_no_free_slot_in_time_runs_here(): void
    {
        $slots = $this->slots(0);
        $tasks = new SummaryTasks('process', slots: $slots, queueSeconds: 0.1);
        $tasks->warm();

        $tasks->start('step', static fn (): int => getmypid());
        $this->assertSame([], $tasks->poll());
        $this->assertGreaterThan(0, $tasks->processes());
        $this->assertSame(0, $slots->taken);
        usleep(150_000);

        $this->assertSame(['step' => ['ok' => true, 'result' => getmypid()]], $tasks->poll());
        $tasks->stop();
    }

    /**
     * Review fix round 2: a step whose process is stopped before it reports has its estimated cost recorded.
     */
    public function test_a_stopped_step_records_its_estimated_cost(): void
    {
        $tasks = new SummaryTasks('process', slots: $this->slots(10));
        $tasks->start('slow', static function (): string {
            sleep(30);

            return 'too late';
        }, ['purpose' => 'summary_judge', 'model' => 'x', 'price' => 'translation', 'input' => 2000, 'output' => 3000]);
        $tasks->poll();

        $tasks->cancel();

        $usage = AiUsage::query()->where('purpose', 'summary_judge')->sole();
        $this->assertSame([2000, 3000], [$usage->input_tokens, $usage->output_tokens]);
        $this->assertTrue($usage->counts_toward_budget);
        $tasks->stop();
    }

    /**
     * Review fix round 3: concurrent summaries can't start more step processes, waiting or working, than the
     * server-wide cap; past it their steps queue, then run in the request process.
     */
    public function test_concurrent_summaries_never_exceed_the_server_wide_process_cap(): void
    {
        $slots = $this->slots(50);
        $slots->spawnCap = 4;
        $summaries = array_map(fn (): SummaryTasks => new SummaryTasks('process', slots: $slots, queueSeconds: 0.2), range(1, 3));

        foreach ($summaries as $n => $tasks) {
            $tasks->warm();

            foreach (range(1, 3) as $i) {
                $tasks->start("s{$n}-{$i}", static function () use ($i): int {
                    usleep(200_000);

                    return $i;
                });
            }
        }

        $done = 0;
        $deadline = microtime(true) + 60;

        while ($done < 9 && microtime(true) < $deadline) {
            foreach ($summaries as $tasks) {
                $done += count(array_filter($tasks->poll(), fn (array $result): bool => $result['ok']));
                $tasks->pause(0.02);
            }

            $this->assertLessThanOrEqual(4, array_sum(array_map(fn (SummaryTasks $tasks): int => $tasks->processes(), $summaries)));
        }

        $this->assertSame(9, $done);
        $this->assertLessThanOrEqual(4, $slots->spawnedPeak);

        foreach ($summaries as $tasks) {
            $tasks->stop();
        }

        $this->assertSame(0, $slots->spawned);
    }

    public function test_steps_run_here_when_the_slots_cannot_be_reached(): void
    {
        $slots = new class extends ProcessSlots
        {
            public function spawn(string $process): bool
            {
                throw new RuntimeException('redis down');
            }

            public function acquire(string $token, string $ticket, string $client): bool
            {
                throw new RuntimeException('redis down');
            }
        };
        $tasks = new SummaryTasks('process', slots: $slots);

        $tasks->start('step', static fn (): string => 'done here');

        $this->assertSame(['step' => ['ok' => true, 'result' => 'done here']], $tasks->poll());
    }

    public function test_cancel_stops_running_steps_and_frees_their_slots(): void
    {
        $slots = $this->slots(10);
        $tasks = new SummaryTasks('process', slots: $slots);
        $tasks->start('slow', static function (): string {
            sleep(30);

            return 'too late';
        });
        $tasks->poll();

        $tasks->cancel();

        $this->assertFalse($tasks->busy());
        $this->assertSame([], $tasks->poll());
        $tasks->stop();
        $this->assertSame(0, $slots->taken);
    }

    public function test_a_step_runs_here_when_its_process_cannot_run(): void
    {
        $tasks = new SummaryTasks('process', ['/nonexistent/php-binary'], $this->slots(10));

        $tasks->start('step', static fn (): string => 'done here');

        $this->assertSame(['step' => ['ok' => true, 'result' => 'done here']], $this->collect($tasks, 1));
    }

    public function test_a_failing_step_is_reported_as_failed(): void
    {
        $tasks = new SummaryTasks('sync');

        $tasks->start('step', static fn () => throw new RuntimeException('down'));

        $this->assertSame(['step' => ['ok' => false, 'result' => null]], $tasks->poll());
        $this->assertFalse($tasks->busy());
    }

    /**
     * In-memory slots, so tests need no Redis.
     */
    private function slots(int $limit): ProcessSlots
    {
        return new class($limit) extends ProcessSlots
        {
            public int $taken = 0;

            public int $peak = 0;

            public int $spawned = 0;

            public int $spawnedPeak = 0;

            public int $spawnCap = 1000;

            /** @var array<string, true> */
            private array $leases = [];

            public function spawn(string $process): bool
            {
                if ($this->spawned >= $this->spawnCap) {
                    return false;
                }

                $this->leases[$process] = true;
                $this->spawned++;
                $this->spawnedPeak = max($this->spawnedPeak, $this->spawned);

                return true;
            }

            public function renewSpawned(string $process): void {}

            public function despawn(string $process): void
            {
                if (isset($this->leases[$process])) {
                    unset($this->leases[$process]);
                    $this->spawned--;
                }
            }

            /** @var array<string, true> */
            private array $tokens = [];

            public function __construct(private int $max) {}

            public function acquire(string $token, string $ticket, string $client): bool
            {
                if ($this->taken >= $this->max) {
                    return false;
                }

                $this->tokens[$token] = true;
                $this->taken++;
                $this->peak = max($this->peak, $this->taken);

                return true;
            }

            public function renew(string $token, string $client): void {}

            public function leave(string $ticket): void {}

            public function release(string $token, string $client): void
            {
                if (isset($this->tokens[$token])) {
                    unset($this->tokens[$token]);
                    $this->taken--;
                }
            }
        };
    }

    /**
     * @param  array<string, array{ok: bool, result: mixed}>  $results
     * @return array<string, array{ok: bool, result: mixed}>
     */
    private function sorted(array $results): array
    {
        uasort($results, fn (array $a, array $b): int => $a['result'] <=> $b['result']);

        return $results;
    }

    /**
     * @return array<string, array{ok: bool, result: mixed}>
     */
    private function collect(SummaryTasks $tasks, int $count): array
    {
        $results = [];
        $deadline = microtime(true) + 60;

        while (count($results) < $count && microtime(true) < $deadline) {
            $results += $tasks->poll();
            $tasks->pause(0.05);
        }

        return $results;
    }
}
