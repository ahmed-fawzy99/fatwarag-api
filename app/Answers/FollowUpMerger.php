<?php

namespace App\Answers;

use App\Services\AiSpend;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Throwable;

/**
 * Merges a follow-up ("what if I was travelling?") with the original question into one complete question, which is
 * then searched afresh. If the model is unavailable or the budget is spent, both are searched together.
 */
class FollowUpMerger implements Agent
{
    use Promptable;

    public const PURPOSE = 'follow_up_merge';

    public function __construct(private AiSpend $spend) {}

    public function instructions(): string
    {
        return <<<'TEXT'
            You rewrite a follow-up question about Islamic rulings into one complete, standalone question that combines it
            with the original question, so it can be searched on its own. Keep the asker's meaning and details exactly;
            add nothing, answer nothing. Write it in the language of the follow-up. Reply with the question only.
            TEXT;
    }

    public function timeout(): int
    {
        return 15;
    }

    public function maxTokens(): int
    {
        return 300;
    }

    public function merge(string $original, string $followUp): string
    {
        $fallback = mb_substr(trim($original).' '.trim($followUp), 0, 1000);

        if ($this->spend->isExhausted()) {
            return $fallback;
        }

        $model = (string) config('fatwarag.openrouter.models.translation');

        $startedAt = hrtime(true);

        try {
            $response = $this->prompt("Original question:\n{$original}\n\nFollow-up:\n{$followUp}", model: $model);
        } catch (Throwable) {
            return $fallback;
        }

        $input = (int) $response->usage->inputTokens;
        $output = (int) $response->usage->outputTokens;
        $this->spend->record(self::PURPOSE, $model, $input, $output, AiSpend::estimate('translation', $input, $output), durationMs: AiSpend::millisecondsSince($startedAt));

        $merged = trim((string) $response->text, " \n\r\t\"'«»");

        return $merged !== '' && mb_strlen($merged) <= 1000 ? $merged : $fallback;
    }
}
