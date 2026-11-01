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
 * The second, independent gate after the lexical safeguard (GroundingCheck): a model (Haiku) reads each summary item
 * next to its verbatim support quotes, or each English field next to its Arabic, and says whether it follows from them
 * alone. Anything but "supported" is dropped; for a claim whose sentences are numbered it also names the sentences it
 * found unsupported, so the caller can prune them and ask once more (SummaryWriter). If the judge can't answer, the
 * caller fails closed.
 */
class EntailmentJudge implements Agent, HasStructuredOutput
{
    use Promptable;

    public const PURPOSE = 'summary_judge';

    public const SUPPORTED = 'supported';

    public function __construct(private AiSpend $spend) {}

    public function instructions(): string
    {
        return <<<'TEXT'
            You are a strict fact-checker for summaries of Islamic fatwas. For each item you get a CLAIM and the EVIDENCE
            it must follow from. Answer for every item id:
            - "supported" only if everything in the claim follows from the evidence alone: every ruling, its strength and
              its polarity, every condition and exception, every number with its unit, and who holds or says it.
              Strength matters: forbidden (يحرم/حرام/لا يجوز) is not disliked (يكره/لا ينبغي); obligatory (يجب/فرض) is
              not recommended (يستحب/سنة/ينبغي); "must" is not "may"; "not obligatory" is not "forbidden".
              A claim that drops a condition the evidence attaches to a ruling, or generalises a ruling the evidence
              gives for a specific case, is not supported. Attributing a view to someone the evidence doesn't name for
              it is not supported. Each piece of evidence is prefixed with [source — mufti], the fatwa it comes from;
              it is either a quote (maybe followed by the clause it sits in) or a passage of the fatwa with the cited
              sentences marked ⟦…⟧. A cited sentence states that source's or mufti's own view only when nothing in it
              or its passage attributes the view to others («ذهب بعضهم», «قال بعض العلماء», «وقيل», «ومنهم
              من», «وذهب الجمهور», "some held", "it is said"). A fatwa that reports a view, and especially one it then
              rejects, does not hold it: a claim or "Held by" that names the source or mufti for a view its quote
              attributes to others is "not_supported". If the passage then gives the fatwa's own, different ruling («والراجح…», «والصحيح…», «لكن…»), a claim
              stating the reported view as the ruling is "not_supported". A sentence naming the proof a view rests on
              («واستدلوا بحديث…», "the view relies on the hadith…") is supported when that verse or hadith is among the
              VERSES AND HADITH THE CLAIM ATTACHES and the sentence describes it as its text says. Judge only by the evidence shown. Ignore
              wording, style and length; neutral framing ("the sources differ", "the first view") needs no evidence.
            - "contradicted" if the evidence says the opposite or a different strength.
            - "not_supported" if the evidence doesn't establish it.
            Use no outside knowledge. For each item write the reason first (what the evidence states and how the claim
            compares), then the verdict that reason leads to; the verdict must agree with the reason.
            When a claim's sentences are numbered ([1], [2]…) and the verdict is not "supported", list in "sentences"
            the numbers of the sentences that state what the evidence doesn't support (the unsupported ruling,
            condition, number or attribution is in that sentence itself). Leave it empty when the verdict is
            "supported", when the problem is the title or "Held by", or when it can't be pinned to whole sentences.
            Set "consensus_only" to true only when the verdict is not "supported" for one reason alone: the claim
            presents as shared by all its sources (an agreed point, "the sources agree", a "Held by" naming several)
            something only some of them state, while every ruling, condition and number in it is stated by at least
            one cited source and none of them contradicts it. Otherwise set it to false.
            TEXT;
    }

    public function timeout(): int
    {
        return 60;
    }

    public function maxTokens(): int
    {
        return 3000;
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
                'verdict' => $schema->string()->enum(['supported', 'contradicted', 'not_supported'])->required(),
                'sentences' => $schema->array()->items($schema->integer())->required(),
                'consensus_only' => $schema->boolean()->required(),
            ]))->required(),
        ];
    }

    /**
     * Summary items against their support quotes.
     *
     * @param  array<string, array{claim: string, quotes: list<string>, context?: list<string>, cited?: list<string>}>  $items
     * @return array<string, array{verdict: string, reason: string, sentences: list<int>}>|null verdicts by id (missing ids = not supported), or null when the judge failed
     */
    public function claims(array $items): ?array
    {
        $blocks = [];

        foreach ($items as $id => $item) {
            $block = "### {$id}\nCLAIM:\n{$item['claim']}\nEVIDENCE (verbatim passages of the cited fatwas, each prefixed with [the source — mufti] whose fatwa it is; the sentences cited for the claim are marked ⟦…⟧ and the rest of the passage is context: whether the fatwa reports a view as others' or gives its own, and any condition or contrary ruling it adds):\n- ".implode("\n- ", $item['quotes']);

            if (($item['context'] ?? []) !== []) {
                $block .= "\nALSO IN THE CITED FATWAS (other sentences that give a different ruling on the same subject; the claim is not supported if they contradict it, limit it or make it conditional in a way the claim drops):\n- ".implode("\n- ", $item['context']);
            }

            if (($item['cited'] ?? []) !== []) {
                $block .= "\nVERSES AND HADITH THE CLAIM ATTACHES (quoted as evidence in the cited fatwas; they establish only that the fatwa cites this proof and what the text itself says, never a ruling, its strength, a condition or who holds a view):\n- ".implode("\n- ", $item['cited']);
            }

            $blocks[] = $block;
        }

        return $this->ask("Check each claim against its evidence.\n\n".implode("\n\n", $blocks), array_keys($items));
    }

    /**
     * English translations against their Arabic: does the English say exactly the same?
     *
     * @param  array<string, array{arabic: string, english: string}>  $pairs
     * @return array<string, array{verdict: string, reason: string, sentences: list<int>}>|null
     */
    public function translations(array $pairs): ?array
    {
        $blocks = [];

        foreach ($pairs as $id => $pair) {
            $blocks[] = "### {$id}\nCLAIM (English):\n{$pair['english']}\nEVIDENCE (the Arabic it translates):\n{$pair['arabic']}";
        }

        return $this->ask(
            "Each claim is an English translation of its Arabic evidence. It is \"supported\" only if it says exactly the same: the same ruling, strength, polarity, conditions, numbers and attributions, nothing added or dropped. Judge meaning, not wording: natural English for the same meaning (word order, glosses of Arabic terms, \"holds\" for «يرى») is supported; a real difference in meaning is not.\n\n".implode("\n\n", $blocks),
            array_keys($pairs),
        );
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{verdict: string, reason: string, sentences: list<int>}>|null
     */
    private function ask(string $request, array $ids): ?array
    {
        if ($ids === []) {
            return [];
        }

        $model = (string) config('fatwarag.openrouter.models.translation');

        $startedAt = hrtime(true);

        try {
            $response = $this->prompt($request, model: $model);
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
            if (! is_array($verdict) || ! in_array($verdict['id'] ?? null, $ids, true)) {
                continue;
            }

            $id = $verdict['id'];
            $value = (string) ($verdict['verdict'] ?? '');

            $sentences = array_values(array_filter(is_array($verdict['sentences'] ?? null) ? $verdict['sentences'] : [], fn (mixed $number): bool => is_int($number) && $number > 0));

            // The same id answered twice: the stricter verdict stands, whatever the order; the sentences it names add up.
            if (! isset($verdicts[$id]) || self::strictness($value) > self::strictness($verdicts[$id]['verdict'])) {
                $verdicts[$id] = ['verdict' => $value, 'reason' => (string) ($verdict['reason'] ?? ''), 'sentences' => [...($verdicts[$id]['sentences'] ?? []), ...$sentences], 'consensusOnly' => ($verdict['consensus_only'] ?? false) === true];
            } else {
                $verdicts[$id]['sentences'] = [...$verdicts[$id]['sentences'], ...$sentences];
            }
        }

        return $verdicts;
    }

    /**
     * How strongly a verdict rejects: supported < not_supported < contradicted < anything unrecognised.
     */
    private static function strictness(string $verdict): int
    {
        return match ($verdict) {
            self::SUPPORTED => 0,
            'not_supported' => 1,
            'contradicted' => 2,
            default => 3,
        };
    }

    /**
     * @param  array<string, array{verdict: string, reason: string}>  $verdicts
     */
    public static function supported(array $verdicts, string $id): bool
    {
        return ($verdicts[$id]['verdict'] ?? null) === self::SUPPORTED;
    }

    /**
     * Whether the judge's only objection is that the claim presents as shared by all its sources what only some state.
     *
     * @param  array<string, array{verdict: string, reason: string, sentences?: list<int>, consensusOnly?: bool}>  $verdicts
     */
    public static function consensusOnly(array $verdicts, string $id): bool
    {
        return ($verdicts[$id]['verdict'] ?? null) !== self::SUPPORTED && ($verdicts[$id]['consensusOnly'] ?? false) === true;
    }

    /**
     * The numbered sentences of a claim the judge found unsupported (1-based); empty when it named none.
     *
     * @param  array<string, array{verdict: string, reason: string, sentences?: list<int>}>  $verdicts
     * @return list<int>
     */
    public static function failingSentences(array $verdicts, string $id): array
    {
        if (self::supported($verdicts, $id)) {
            return [];
        }

        return array_values(array_unique(array_filter($verdicts[$id]['sentences'] ?? [], fn (mixed $number): bool => is_int($number) && $number > 0)));
    }

    /**
     * A claim's text as the judge reads it, its sentences numbered («[1] … [2] …») when there are two or more, so the
     * judge can name the ones the evidence doesn't support (GroundingCheck::sentences()).
     */
    public static function numbered(string $text): string
    {
        $sentences = GroundingCheck::sentences($text);

        if (count($sentences) < 2) {
            return $text;
        }

        return implode(' ', array_map(fn (string $sentence, int $i): string => '['.($i + 1).'] '.$sentence, $sentences, array_keys($sentences)));
    }
}
