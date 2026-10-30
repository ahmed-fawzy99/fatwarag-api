<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\SerializableClosure\SerializableClosure;
use Throwable;

/**
 * One background step of a summary (a judge or translation call), run by SummaryTasks in its own process. The process
 * starts before it has work, so the app is already booted when the step arrives on stdin; with no input it exits.
 * It prints `{"ok": true, "result": <base64 serialized>}` or `{"ok": false}`, and never the step's text.
 */
class RunSummaryTask extends Command
{
    protected $signature = 'answers:summary-task';

    protected $description = 'Run one background step of an answer summary (internal, read from stdin)';

    protected $hidden = true;

    public function handle(): int
    {
        $payload = trim((string) stream_get_contents(STDIN));

        if ($payload === '') {
            return self::SUCCESS;
        }

        try {
            $task = unserialize((string) base64_decode($payload, true));

            if (! $task instanceof SerializableClosure) {
                throw new \UnexpectedValueException('Not a task.');
            }

            $this->output->write((string) json_encode(['ok' => true, 'result' => base64_encode(serialize($task()))]));
        } catch (Throwable $exception) {
            // Only the class: a message could carry the summary's text, and the question is never logged.
            $this->output->write((string) json_encode(['ok' => false, 'error' => $exception::class]));
        }

        return self::SUCCESS;
    }
}
