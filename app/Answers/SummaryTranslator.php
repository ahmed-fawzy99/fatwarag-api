<?php

namespace App\Answers;

use App\Services\AiSpend;
use App\Support\Arabic;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Translates a summary that already passed the safeguard into English, field by field (Haiku). English readers see
 * this translation, never English the summary model wrote itself, so everything shown is grounded.
 *
 * Each translated field must keep its Arabic field's numbers and rulings (lexically) and be judged an exact
 * translation by the entailment judge; a point whose title, body or text fails is dropped, a failing `heldBy` becomes
 * null and a failing headline is replaced by the agreed title. If the judge can't answer, the translation fails.
 */
class SummaryTranslator implements Agent, HasStructuredOutput
{
    use Promptable;

    public const PURPOSE = 'summary_translation';

    public static function differHeadline(int $views): string
    {
        return match (true) {
            $views === 2 => 'The sources differ on this question, giving two views',
            $views === 3 => 'The sources differ on this question, giving three views',
            $views === 4 => 'The sources differ on this question, giving four views',
            default => 'The sources differ on this question',
        };
    }

    public const AGREE_TITLE = 'What the sources agree on';

    /** The English of GroundingCheck::SOURCES_SAY_HEADLINE: neutral framing, so it isn't translated. */
    public const SOURCES_SAY_HEADLINE = 'What the sources say on this question';

    /**
     * The English neutral label for a view whose Arabic title failed its check («القول الثاني»): neutral framing, like
     * the "differ" headline, so it isn't translated.
     */
    public static function viewLabel(int $index): string
    {
        return 'View '.($index + 1);
    }

    public function __construct(private AiSpend $spend, private NumberReader $numbers, private Polarity $polarity, private ?EntailmentJudge $judge = null) {}

    public function instructions(): string
    {
        return <<<'TEXT'
            You translate the fields of a summary of Islamic fatwas from Arabic into clear, natural English for ordinary
            readers. Translate each field faithfully and completely: add nothing, drop nothing, soften or strengthen
            nothing. Keep every negation. Add no modal or strength word the Arabic doesn't have (must, should, may,
            better): «يكون الجمع قبل…» is "the combining is before…", not "should be". Keep every number exactly as written in the Arabic (write digits for numbers
            given in digits, words or digits for numbers given in words) and keep its unit or measure; do not convert
            units or add percentages. Use the English names given in the glossary for sources and muftis, the same way
            every time. Keep Arabic technical terms the usual way (zakat, nisab, mithqal, mushaf, junub). Return one
            translation for every field id.
            TEXT;
    }

    public function timeout(): int
    {
        return 60;
    }

    public function maxTokens(): int
    {
        return 4000;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'fields' => $schema->array()->items($schema->object([
                'id' => $schema->string()->required(),
                'text' => $schema->string()->required(),
            ]))->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary  the Arabic Summary (public shape)
     * @param  array<string, string>  $glossary  Arabic name => English name, for sources and muftis
     * @return array<string, mixed>|null the English Summary, or null when it can't be translated faithfully
     */
    public function translate(array $summary, array $glossary): ?array
    {
        $translations = $this->draft($this->fields($summary), $glossary);

        return $translations === null ? null : $this->verify($summary, $translations);
    }

    /**
     * The first step: the model's translation of each field, keeping only those that keep their Arabic's numbers and
     * rulings. It may run before the summary is final (side by side with the claims judge); `verify()` then uses only
     * the translations of the Arabic that survived.
     *
     * @param  array<string, string>  $fields  field id => Arabic
     * @param  array<string, string>  $glossary  Arabic name => English name, for sources and muftis
     * @return array<string, string>|null Arabic => English, or null when the model failed
     */
    public function draft(array $fields, array $glossary): ?array
    {
        $model = (string) config('fatwarag.openrouter.models.translation');
        $startedAt = hrtime(true);

        try {
            $response = $this->prompt($this->request($fields, $glossary), model: $model);
        } catch (Throwable) {
            return null;
        }

        $input = (int) ($response->usage->inputTokens ?? 0);
        $output = (int) ($response->usage->outputTokens ?? 0);
        $this->spend->record(self::PURPOSE, $model, $input, $output, AiSpend::estimate('translation', $input, $output), durationMs: AiSpend::millisecondsSince($startedAt));

        $translations = [];

        foreach ($response instanceof StructuredAgentResponse ? ($response->toArray()['fields'] ?? []) : [] as $field) {
            if (is_array($field) && isset($field['id'], $field['text']) && isset($fields[$field['id']])) {
                $english = trim((string) $field['text']);

                if ($english !== '' && $this->faithful($fields[$field['id']], $english)) {
                    $translations[$fields[$field['id']]] = $english;
                }
            }
        }

        return $translations;
    }

    /**
     * The second step, on the final checked summary: each of its fields takes the drafted translation of exactly its
     * Arabic (anything the claims judge dropped or changed has none), the judge confirms each English field says
     * exactly what its Arabic says, and the English summary is assembled from what passes.
     *
     * @param  array<string, mixed>  $summary  the final Arabic Summary (public shape)
     * @param  array<string, string>  $translations  Arabic => English, from `draft()`
     * @return array<string, mixed>|null the English Summary, or null when it can't be translated faithfully
     */
    public function verify(array $summary, array $translations): ?array
    {
        $fields = $this->fields($summary);
        $translated = [];

        foreach ($fields as $id => $arabic) {
            if (isset($translations[$arabic])) {
                $translated[$id] = $translations[$arabic];
            }
        }

        // Second gate: the judge confirms each English field says exactly what its Arabic says.
        $verdicts = ($this->judge ?? app(EntailmentJudge::class))->translations(array_map(
            fn (string $id): array => ['arabic' => $fields[$id], 'english' => $translated[$id]],
            array_combine(array_keys($translated), array_keys($translated)) ?: [],
        ));

        if ($verdicts === null) {
            return null; // fail closed
        }

        $translated = array_filter($translated, fn (string $id): bool => EntailmentJudge::supported($verdicts, $id), ARRAY_FILTER_USE_KEY);

        return $this->assemble($summary, $translated);
    }

    /**
     * The translation keeps the Arabic's numbers and rulings and adds no voice of its own:
     * - the same numbers;
     * - every strong ruling the English states (forbidden, must, not obligatory, permissible…) is one the Arabic
     *   states, and every obligatory / forbidden / not-obligatory ruling of the Arabic is stated in the English
     *   ("may" alone is too common in English to require or read, so permitted is left to the entailment judge);
     * - no "we" / "our view".
     */
    public function faithful(string $arabic, string $english): bool
    {
        $arabicNumbers = $this->numbers->values($arabic);
        $englishNumbers = $this->numbers->values($english);

        // Every Arabic number is in the English (any of its readings: «الخمس» is five or a fifth), and the English adds
        // none, except small counts the Arabic says in words the reader doesn't take as numbers («ثلاثًا», «الرباعية»).
        foreach ($this->numbers->occurrences($arabic) as $occurrence) {
            if (array_intersect($occurrence['values'], $englishNumbers) === []) {
                return false;
            }
        }

        foreach (array_diff($englishNumbers, $arabicNumbers) as $added) {
            if (! ctype_digit($added) || (int) $added > 12) {
                return false;
            }
        }

        $arabicRulings = array_unique($this->polarity->rulings($arabic));
        $englishRulings = array_unique($this->polarity->englishRulings($english));

        // A ruling the English adds fails when it can't hold with one the Arabic states («permitted» for «يحرم»), or
        // the Arabic states none; a near wording («lawful» for «مشروعة») is left to the translation judge.
        foreach (array_diff($englishRulings, [...$arabicRulings, ...$this->impliedRulings($arabic)]) as $added) {
            if ($arabicRulings === [] || $this->polarity->opposed([$added], $arabicRulings)) {
                return false;
            }
        }

        if (array_diff(array_intersect($arabicRulings, ['obligatory', 'forbidden', 'not-obligatory']), $englishRulings) !== []) {
            return false;
        }

        return ! preg_match('/\b(we|our|ours|us)\b/u', mb_strtolower($english));
    }

    /**
     * Rulings Arabic states by construction rather than by a ruling word, which English renders with one:
     * «لا تمسّ» (a prohibition: "must not touch", "should not touch"), «عليها أن» / «لا بد» ("must"), «لها أن» ("may").
     *
     * @return list<string>
     */
    private function impliedRulings(string $arabic): array
    {
        $text = ' '.Arabic::normalize($arabic).' ';
        $implied = [];

        if (preg_match('/\s(و|ف)?لا\s+[تيان]\S{2,}/u', $text)) {
            array_push($implied, 'forbidden', 'disliked');
        }

        if (preg_match('/\s(علي|عليه|عليها|عليهم|عليك|علينا)\s+ان\s|\sلا\s+بد\s/u', $text)) {
            $implied[] = 'obligatory';
        }

        if (preg_match('/\s(له|لها|لهم|لك)\s+ان\s/u', $text)) {
            $implied[] = 'permitted';
        }

        return $implied;
    }

    /**
     * Every text field of the summary by a stable id.
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, string>
     */
    public function fields(array $summary): array
    {
        // A "differ" headline is a neutral template (GroundingCheck::differHeadline), so it isn't translated.
        $fields = $summary['kind'] === 'agree' ? ['headline' => (string) $summary['headline']] : [];
        $points = array_filter(['agreed' => $summary['agreed'], ...array_combine(
            array_map(fn (int $i): string => "view{$i}", array_keys($summary['views'])),
            $summary['views'],
        )]);

        foreach ($points as $key => $point) {
            $fields["{$key}.title"] = (string) $point['title'];
            $fields["{$key}.body"] = (string) $point['body'];

            if (filled($point['heldBy'])) {
                $fields["{$key}.heldBy"] = (string) $point['heldBy'];
            }
        }

        foreach ($summary['preferences'] as $i => $preference) {
            $fields["preference{$i}.text"] = (string) $preference['text'];
        }

        if ($summary['caution']) {
            $fields['caution.text'] = (string) $summary['caution']['text'];
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $glossary
     */
    private function request(array $fields, array $glossary): string
    {
        $names = implode("\n", array_map(fn (string $ar, string $en): string => "{$ar} = {$en}", array_keys($glossary), $glossary));

        return "Glossary of names:\n{$names}\n\nFields to translate (JSON, id => Arabic):\n"
            .json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * The English Summary from the Arabic one and the translations that passed, by field id (see `fields()`): a point
     * whose title or body has none is dropped, a `heldBy` without one becomes null.
     *
     * @param  array<string, mixed>  $summary
     * @param  array<string, string>  $translated
     * @return array<string, mixed>|null
     */
    public function assemble(array $summary, array $translated): ?array
    {
        $point = function (array $point, string $key) use ($translated): ?array {
            if (! isset($translated["{$key}.title"], $translated["{$key}.body"])) {
                return null;
            }

            return [
                ...$point,
                'title' => $translated["{$key}.title"],
                'heldBy' => filled($point['heldBy']) ? ($translated["{$key}.heldBy"] ?? null) : null,
                'body' => $translated["{$key}.body"],
            ];
        };

        $agreed = $summary['agreed'] ? $point($summary['agreed'], 'agreed') : null;
        $views = array_values(array_filter(array_map(fn (array $view, int $i): ?array => $point($view, "view{$i}"), $summary['views'], array_keys($summary['views']))));

        if ($summary['kind'] === 'differ' ? count($views) < 2 : $agreed === null) {
            return null;
        }

        $preferences = [];

        foreach ($summary['preferences'] as $i => $preference) {
            if (isset($translated["preference{$i}.text"])) {
                $preferences[] = [...$preference, 'text' => $translated["preference{$i}.text"]];
            }
        }

        $caution = $summary['caution'] && isset($translated['caution.text'])
            ? [...$summary['caution'], 'text' => $translated['caution.text']]
            : null;

        return [
            ...$summary,
            'headline' => $summary['kind'] === 'agree' ? ($translated['headline'] ?? $agreed['title'] ?? self::AGREE_TITLE) : self::differHeadline(count($views)),
            'agreed' => $agreed,
            'views' => $views,
            'preferences' => $preferences,
            'caution' => $caution,
        ];
    }
}
