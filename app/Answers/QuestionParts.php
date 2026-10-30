<?php

namespace App\Answers;

use App\Services\AiSpend;
use App\Support\Arabic;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Finds the parts of a question that asks several things («أولًا لو كنت داخل الصلاة، ثانيًا لو بعد الصلاة…»), so each
 * part is searched on its own (owner request). A cheap check on the wording decides first whether the question may
 * have parts at all, so a single question costs no call and no time; only then a fast model (Haiku) names the parts.
 * Any failure means one part: the question is searched as before.
 */
class QuestionParts implements Agent, HasStructuredOutput
{
    use Promptable;

    public const PURPOSE = 'question_parts';

    public const MAX_PARTS = SummaryWriter::MAX_PARTS;

    public function __construct(private AiSpend $spend) {}

    public function instructions(): string
    {
        return <<<'TEXT'
            You split a question about Islamic rulings into its parts, only when the asker asks several distinct things:
            they number them (first… second…, أولًا… ثانيًا…), or ask about clearly separate cases ("if during the prayer…,
            and if after it…"), or ask two different questions. A question asking one thing, however long or detailed, has
            no parts: return an empty list. Never split one question into aspects of your own.
            For each part (2 to 4, in the asker's order) give:
            - label: a short Arabic phrase naming the case, phrased from the question, e.g. «إذا كان الشك أثناء الصلاة».
              It only names the case: no ruling, no answer, no negation.
            - query: a complete, standalone question for that part alone, in the question's language, keeping the
              asker's shared context (the situation both parts are about), so it can be searched on its own.
            Answer nothing.
            TEXT;
    }

    public function timeout(): int
    {
        return 15;
    }

    public function maxTokens(): int
    {
        return 600;
    }

    public function temperature(): float
    {
        return 0.0;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'parts' => $schema->array()->items($schema->object([
                'label' => $schema->string()->required(),
                'query' => $schema->string()->required(),
            ]))->required(),
        ];
    }

    /**
     * Whether the wording suggests several questions: numbered parts, several question marks, or a second case opened
     * by «وإذا/ولو/وإن كان/وفي حال…» or "and if / and what if / but if / what about". A word like "also" («وأيضًا») alone
     * isn't enough. Most questions don't pass, and pay nothing more.
     */
    public static function mayHaveParts(string $question): bool
    {
        $text = ' '.Arabic::normalize($question).' ';
        $lower = ' '.mb_strtolower($question).' ';

        return preg_match('/[\s،,:]\.?(اولا|ثانيا|ثالثا|اولهما|ثانيهما)[\s،,:]/u', $text) === 1
            || preg_match('/\s[1١][\.\-\)]\s*\S.*\s[2٢][\.\-\)]/su', $text) === 1
            || substr_count($question, '؟') + substr_count($question, '?') >= 2
            || preg_match('/\S\s+(واذا|ولو|وان\s+كان|وفي\s+حال|وماذا\s+لو|اما\s+اذا|اما\s+لو)\s/u', $text) === 1
            || preg_match('/\bfirst(ly)?\b.*\bsecond(ly)?\b|\b(and|but)\s+(if|what if|what about)\b|\bwhat about\b/u', $lower) === 1;
    }

    /**
     * The question's parts, or [] for one part (the common case, any failure, or a spent budget).
     *
     * @return list<array{label: string, query: string}>
     */
    public function detect(string $question): array
    {
        if (! self::mayHaveParts($question) || $this->spend->isExhausted()) {
            return [];
        }

        $model = (string) config('fatwarag.openrouter.models.translation');
        $startedAt = hrtime(true);

        try {
            $response = $this->prompt("Question:\n{$question}", model: $model);
        } catch (Throwable $exception) {
            // Never the question: only the error's class.
            Log::info('Question parts unavailable; searched as one', ['error' => $exception::class]);

            return [];
        }

        $input = (int) ($response->usage->inputTokens ?? 0);
        $output = (int) ($response->usage->outputTokens ?? 0);
        $this->spend->record(self::PURPOSE, $model, $input, $output, AiSpend::estimate('translation', $input, $output), durationMs: AiSpend::millisecondsSince($startedAt));

        $parts = [];

        foreach ($response instanceof StructuredAgentResponse ? ($response->toArray()['parts'] ?? []) : [] as $part) {
            $label = is_array($part) ? trim((string) ($part['label'] ?? '')) : '';
            $query = is_array($part) ? trim((string) ($part['query'] ?? '')) : '';

            if ($label !== '' && $query !== '' && mb_strlen($label) <= 120 && mb_strlen($query) <= 1000) {
                $parts[] = ['label' => $label, 'query' => $query];
            }
        }

        return count($parts) >= 2 ? array_slice($parts, 0, self::MAX_PARTS) : [];
    }
}
