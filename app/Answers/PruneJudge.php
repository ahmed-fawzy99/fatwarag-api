<?php

namespace App\Answers;

use App\Services\AiSpend;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Judges a pruned summary item once more: the claims judge named sentences it found unsupported, they were removed
 * (GroundingCheck::prune()), and this reads the kept text beside the removed sentences and the evidence. Besides
 * whether the kept text follows from the evidence, it answers three omission questions; a yes to any, a missing
 * verdict or a failed call drops the item (it is never shown pruned):
 * - does the kept text state a ruling without a condition, restriction, exception or scope the evidence or the removed
 *   sentences attach to it;
 * - does any reference in it («هذا», «به», «ويشترط لذلك», "the majority hold this") now point to something else or to
 *   nothing;
 * - does it attribute a view to a different holder than the evidence does.
 *
 * No word list decides any of this: removing a sentence can change what the rest means in more ways than a list can
 * name, so it is read as a whole.
 */
class PruneJudge implements Agent, HasStructuredOutput
{
    use Promptable;

    public const PURPOSE = 'summary_prune_judge';

    public function __construct(private AiSpend $spend) {}

    public function instructions(): string
    {
        return <<<'PROMPT'
            You check summaries of Islamic fatwas after sentences were removed from them. For each item you get the KEPT
            TEXT (what readers will see), the REMOVED SENTENCES (taken out because they were not supported), and the
            EVIDENCE: verbatim passages of the cited fatwas, each prefixed with [source — mufti], cited sentences marked
            ⟦…⟧. Removing a sentence can change what the rest means. Answer for every item id, each question on its own:
            - "drops_condition": true if the kept text states any ruling without a condition, restriction, exception,
              time or scope that the evidence or a removed sentence attaches to that ruling (for example «بإذن زوجها»,
              «ويشترط لذلك…», «ويكره ذلك بعد الزوال», «في الفريضة دون النافلة», "only when…", "except…").
            - "dangling_reference": true if any word or phrase in the kept text that refers to something else («هذا»،
              «ذلك»، «به»، «عليه»، «لذلك»، «وهو قول الجمهور», "this", "it", "the majority hold this") now points to a
              different statement than it did, or to nothing, because a sentence was removed.
            - "wrong_holder": true if the kept text attributes a view or ruling to a different holder (a source, a
              mufti, "the majority", "some scholars") than the evidence does.
            - "verdict": "supported" only if everything in the kept text follows from the evidence alone, with the same
              strength, polarity, conditions, numbers and holder; else "not_supported" or "contradicted".
            Use no outside knowledge. Write the reason first, then the answers; they must agree with the reason. When in
            doubt, answer true for the omission questions.
            PROMPT;
    }

    public function timeout(): int
    {
        return 60;
    }

    public function maxTokens(): int
    {
        return 2000;
    }

    public function temperature(): float
    {
        return 0.0;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'verdicts' => $schema->array()->items($schema->object([
                'id' => $schema->string()->required(),
                'reason' => $schema->string()->required(),
                'drops_condition' => $schema->boolean()->required(),
                'dangling_reference' => $schema->boolean()->required(),
                'wrong_holder' => $schema->boolean()->required(),
                'verdict' => $schema->string()->enum(['supported', 'contradicted', 'not_supported'])->required(),
            ]))->required(),
        ];
    }

    /**
     * @param  array<string, array{claim: string, removed: list<string>, quotes: list<string>, context?: list<string>}>  $items
     * @return array<string, array<string, mixed>>|null verdicts by id, or null when the call failed
     */
    public function judge(array $items): ?array
    {
        if ($items === []) {
            return [];
        }

        $blocks = [];

        foreach ($items as $id => $item) {
            $block = "### {$id}\nKEPT TEXT:\n{$item['claim']}\nREMOVED SENTENCES:\n- ".implode("\n- ", $item['removed'])
                ."\nEVIDENCE:\n- ".implode("\n- ", $item['quotes']);

            if (($item['context'] ?? []) !== []) {
                $block .= "\nALSO IN THE CITED FATWAS (other sentences on the same subject):\n- ".implode("\n- ", $item['context']);
            }

            $blocks[] = $block;
        }

        $model = (string) config('fatwarag.openrouter.models.translation');
        $startedAt = hrtime(true);

        try {
            $response = $this->prompt("Check each kept text.\n\n".implode("\n\n", $blocks), model: $model);
        } catch (Throwable) {
            return null;
        }

        $input = (int) ($response->usage->inputTokens ?? 0);
        $output = (int) ($response->usage->outputTokens ?? 0);
        $this->spend->record(self::PURPOSE, $model, $input, $output, AiSpend::estimate('translation', $input, $output), durationMs: AiSpend::millisecondsSince($startedAt));

        if (! $response instanceof StructuredAgentResponse || ! is_array($response->toArray()['verdicts'] ?? null)) {
            return null;
        }

        $verdicts = [];

        foreach ($response->toArray()['verdicts'] as $verdict) {
            if (is_array($verdict) && isset($items[$verdict['id'] ?? ''])) {
                // The same id answered twice: a failing answer stands.
                $verdicts[$verdict['id']] = isset($verdicts[$verdict['id']]) && ! self::passes($verdicts, $verdict['id']) ? $verdicts[$verdict['id']] : $verdict;
            }
        }

        return $verdicts;
    }

    /**
     * Whether a pruned item may be shown: supported, and no to every omission question (a missing answer is a no-pass).
     *
     * @param  array<string, array<string, mixed>>  $verdicts
     */
    public static function passes(array $verdicts, string $id): bool
    {
        $verdict = $verdicts[$id] ?? null;

        return is_array($verdict)
            && ($verdict['verdict'] ?? null) === EntailmentJudge::SUPPORTED
            && ($verdict['drops_condition'] ?? true) === false
            && ($verdict['dangling_reference'] ?? true) === false
            && ($verdict['wrong_holder'] ?? true) === false;
    }
}
