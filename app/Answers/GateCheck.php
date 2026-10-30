<?php

namespace App\Answers;

/**
 * A started off-topic check (QuestionGate::begin): collected once the search is done. Waits only as long as the gate's
 * budget allows; a check that fails or runs out of time lets the question through.
 */
class GateCheck
{
    private ?bool $verdict;

    private float $startedAt;

    public function __construct(private QuestionGate $gate, ?bool $verdict, private ?SummaryTasks $tasks = null)
    {
        $this->verdict = $verdict;
        $this->startedAt = microtime(true);
    }

    /**
     * Whether the question may be answered (false: clearly not religious).
     */
    public function allowed(): bool
    {
        if ($this->verdict !== null || $this->tasks === null) {
            return $this->verdict ?? true;
        }

        // The model's own timeout, plus a moment for the step's process to start.
        $deadline = $this->startedAt + (float) config('fatwarag.ask.gate_wait_seconds', 5);
        $reason = 'timeout';

        while (microtime(true) < $deadline) {
            $outcome = $this->tasks->poll()['gate'] ?? null;

            if ($outcome !== null) {
                $reason = 'no verdict';
                $this->verdict = $outcome['ok'] && is_bool($outcome['result']) ? $outcome['result'] : null;

                break;
            }

            $this->tasks->pause();
        }

        $this->tasks->stop();
        $this->tasks = null;

        if ($this->verdict === null) {
            $this->gate->failedOpen($reason);
        }

        return $this->verdict ?? true;
    }
}
