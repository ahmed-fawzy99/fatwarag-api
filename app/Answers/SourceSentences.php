<?php

namespace App\Answers;

use App\Models\Fatwa;

/**
 * The numbered sentences of the fatwas a summary reads, so the summary can cite them by id.
 *
 * Each fatwa the model reads gets a label («F2») and each sentence of its answer and source summary a stable number
 * («[F2.S14] …»), counted over the whole text so an excerpt keeps the same numbers. The model cites sentence ids
 * instead of copying quotes; `resolve()` turns them back into the exact stored sentences, which then go through every
 * check as the support quotes. A fatwa's question gets no numbers: support never comes from the questioner's words.
 * Quoted verses and hadith are numbered as evidence markers («[F2.E3 آية]«…»») and are never split.
 */
class SourceSentences
{
    /** A fragment shorter than this many words («نعم.», «قال تعالى:») is merged with its neighbour. */
    public const MIN_SENTENCE_WORDS = 4;

    /**
     * Where a sentence ends: one of «. ! ? ؟ ؛» before a space or the end, or a line break. Never «:», so a lead-in
     * stays with what it introduces («ذكر بعض الفقهاء أقوالا، منها: يجوز…»).
     */
    private const BOUNDARY = '/(?<=[.!?؟؛])(?=\s|$)|\R+/u';

    /** @var array<string, string> label («F1») => fatwa public id */
    private array $fatwaIds = [];

    /** @var array<string, array<int, string>> label => sentence number => the sentence, verbatim */
    private array $sentences = [];

    /** @var array<string, array<int, array<string, mixed>>> fatwa public id => marker number => quoted verse or hadith segment */
    private array $markers = [];

    /** @var array<string, array<string, list<list<array{id: string, text: string, display: string}>>>> label => part => paragraphs */
    private array $paragraphs = [];

    /** @var array<string, array<int, array{0: int, 1: int}>> label => sentence number => [paragraph index, position in it] */
    private array $place = [];

    /** @var array<string, list<list<int>>> label => paragraph index => its sentence numbers */
    private array $members = [];

    /**
     * Numbers a fatwa's source summary and answer, in that order. Returns its label.
     */
    public function addFatwa(Fatwa $fatwa): string
    {
        return $this->add($fatwa->public_id, [
            'summary' => array_values(array_filter($fatwa->source_summary ?? [], 'is_array')),
            'answer' => array_values(array_filter($fatwa->answer ?? [], 'is_array')),
        ]);
    }

    /**
     * Numbers a plain text (one paragraph per line); a leading «السؤال: … الجواب:» is left out.
     */
    public function addText(string $fatwaId, string $text): string
    {
        $lines = preg_split('/\R/u', GroundingCheck::withoutQuestion($text)) ?: [];

        return $this->add($fatwaId, ['summary' => [], 'answer' => array_map(
            fn (string $line): array => [['type' => 'text', 'text' => $line]],
            array_values(array_filter($lines, fn (string $line): bool => trim($line) !== '')),
        )]);
    }

    public function label(string $fatwaId): ?string
    {
        $label = array_search($fatwaId, $this->fatwaIds, true);

        return $label === false ? null : $label;
    }

    public function fatwaId(string $label): ?string
    {
        return $this->fatwaIds[$label] ?? null;
    }

    /**
     * @return array<string, string> label => fatwa public id
     */
    public function labels(): array
    {
        return $this->fatwaIds;
    }

    /** How many sentences after a cited one are read for the fatwa's own contrary view (the rest of a long fatwa). */
    public const CONTEXT_AFTER = 60;

    /**
     * The stored sentence a sentence id («F2.S14») points to, with what surrounds it in the fatwa:
     * - `before`: the sentences before it back to its paragraph's start (and the paragraph before, when it is among its
     *   paragraph's first two sentences), nearest last; the guard stops at the fatwa's last turn to its own voice;
     * - `after`: the sentences after it to the end of the fatwa (at most CONTEXT_AFTER), across paragraphs;
     * - `passage`: the paragraph it sits in (and the previous one when it is among its paragraph's first two
     *   sentences), as `{key, sentences: [number => text]}`, which the judge reads whole.
     *
     * @return array{fatwaId: string, quote: string, id: string, before: list<string>, after: list<string>, passage: array{key: string, sentences: array<int, string>}}|null
     */
    public function sentence(string $id): ?array
    {
        if (! preg_match('/^(F\d+)\.S(\d+)$/', trim($id), $match) || ! isset($this->sentences[$match[1]][(int) $match[2]])) {
            return null;
        }

        $label = $match[1];
        $number = (int) $match[2];
        [$paragraph, $position] = $this->place[$label][$number];
        $members = $this->members[$label][$paragraph];
        // Back to the paragraph's start; when the sentence opens its paragraph (a list after «…أقوالا منها:»), the
        // paragraph before too.
        $scope = $position < 2 && $paragraph > 0 ? [...$this->members[$label][$paragraph - 1], ...$members] : $members;
        $before = array_map(fn (int $n): string => $this->sentences[$label][$n], array_values(array_filter($scope, fn (int $n): bool => $n < $number)));
        $after = [];

        for ($n = $number + 1; isset($this->sentences[$label][$n]) && count($after) < self::CONTEXT_AFTER; $n++) {
            $after[] = $this->sentences[$label][$n];
        }

        $passage = $position < 2 && $paragraph > 0 ? [...$this->members[$label][$paragraph - 1], ...$members] : $members;

        return [
            'fatwaId' => $this->fatwaIds[$label],
            'quote' => $this->sentences[$label][$number],
            'id' => trim($id),
            'before' => $before,
            'after' => $after,
            'passage' => [
                'key' => "{$label}.P".($position < 2 && $paragraph > 0 ? $paragraph - 1 : $paragraph),
                'sentences' => array_combine($passage, array_map(fn (int $n): string => $this->sentences[$label][$n], $passage)),
            ],
        ];
    }

    /**
     * Every stored sentence of a fatwa, by number.
     *
     * @return array<int, string>
     */
    public function sentencesOf(string $fatwaId): array
    {
        $label = $this->label($fatwaId);

        return $label === null ? [] : $this->sentences[$label];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>> fatwa public id => marker number => segment
     */
    public function markers(): array
    {
        return $this->markers;
    }

    /**
     * A fatwa's numbered paragraphs, as the model reads them.
     *
     * @param  'summary'|'answer'  $part
     * @return list<list<array{id: string, text: string, display: string}>>
     */
    public function paragraphs(string $label, string $part): array
    {
        return $this->paragraphs[$label][$part] ?? [];
    }

    /**
     * One paragraph as the model reads it: each sentence after its id.
     *
     * @param  list<array{id: string, text: string, display: string}>  $sentences
     */
    public static function line(array $sentences): string
    {
        return implode(' ', array_map(fn (array $sentence): string => "[{$sentence['id']}] {$sentence['display']}", $sentences));
    }

    /**
     * Turns the model's ids back into what the checks read. Citations («F2») become fatwa public ids; each support id
     * becomes `{fatwaId, quote}` with the stored sentence as the quote, and an unknown id, or one from a fatwa the item
     * doesn't cite, is dropped; evidence ids («F2.E3») become `{fatwaId, marker}`.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function resolve(array $item): array
    {
        $labels = array_values(array_unique(array_filter(
            array_map(fn (mixed $label): string => trim((string) (is_scalar($label) ? $label : '')), self::listOf($item['citations'] ?? [])),
            fn (string $label): bool => isset($this->fatwaIds[$label]),
        )));

        $support = [];

        foreach (array_unique(array_map(fn (mixed $id): string => trim((string) (is_scalar($id) ? $id : '')), self::listOf($item['support'] ?? []))) as $id) {
            $sentence = $this->sentence($id);

            if ($sentence !== null && in_array(explode('.', $id)[0], $labels, true)) {
                $support[] = $sentence;
            }
        }

        $evidence = [];

        foreach (self::listOf($item['evidence'] ?? []) as $reference) {
            if (is_scalar($reference) && preg_match('/^(F\d+)\.E(\d+)$/', trim((string) $reference), $match) && isset($this->fatwaIds[$match[1]])) {
                $evidence[] = ['fatwaId' => $this->fatwaIds[$match[1]], 'marker' => (int) $match[2]];
            }
        }

        return [
            ...$item,
            'citations' => array_map(fn (string $label): string => $this->fatwaIds[$label], $labels),
            'evidence' => $evidence,
            'support' => $support,
        ];
    }

    /**
     * @param  array<string, list<list<array<string, mixed>>>>  $parts  paragraphs of segments, by part
     */
    private function add(string $fatwaId, array $parts): string
    {
        $label = 'F'.(count($this->fatwaIds) + 1);
        $this->fatwaIds[$label] = $fatwaId;
        $this->sentences[$label] = [];
        $this->markers[$fatwaId] = [];
        $this->paragraphs[$label] = ['summary' => [], 'answer' => []];
        $this->place[$label] = [];
        $this->members[$label] = [];

        foreach ($parts as $part => $paragraphs) {
            foreach ($paragraphs as $paragraph) {
                $numbered = [];

                $index = count($this->members[$label]);

                foreach (self::split($paragraph) as $position => $pieces) {
                    $number = count($this->sentences[$label]) + 1;
                    $this->place[$label][$number] = [$index, $position];
                    $this->members[$label][$index][] = $number;
                    [$text, $display] = $this->render($pieces, $label, $fatwaId);
                    $this->sentences[$label][$number] = $text;
                    $numbered[] = ['id' => "{$label}.S{$number}", 'text' => $text, 'display' => $display];
                }

                if ($numbered !== []) {
                    $this->paragraphs[$label][$part][] = $numbered;
                }
            }
        }

        return $label;
    }

    /**
     * A sentence's verbatim text, and its display with each quoted verse or hadith marked.
     *
     * @param  list<string|array<string, mixed>>  $pieces
     * @return array{0: string, 1: string}
     */
    private function render(array $pieces, string $label, string $fatwaId): array
    {
        $text = '';
        $display = '';

        foreach ($pieces as $piece) {
            if (is_string($piece)) {
                $text .= $piece;
                $display .= $piece;

                continue;
            }

            $marker = count($this->markers[$fatwaId]) + 1;
            $this->markers[$fatwaId][$marker] = $piece;
            $text .= (string) $piece['text'];
            $display .= "[{$label}.E{$marker} ".($piece['type'] === 'quran' ? 'آية' : 'حديث').']«'.$piece['text'].'»';
        }

        return [trim($text), trim($display)];
    }

    /**
     * A paragraph's sentences, each as its pieces: text, or a whole quoted verse or hadith segment. Pieces keep their
     * own spacing, so a sentence (or two merged) is always a verbatim stretch of the paragraph.
     *
     * @param  list<array<string, mixed>>  $paragraph
     * @return list<list<string|array<string, mixed>>>
     */
    public static function split(array $paragraph): array
    {
        $sentences = [];
        $current = [];

        foreach ($paragraph as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            if (self::isQuote($segment)) {
                $current[] = $segment;

                // A quote that ends its own sentence («…حلي النساء.») ends the fatwa's sentence too.
                if (preg_match('/[.!?؟؛]\s*$/u', (string) ($segment['text'] ?? ''))) {
                    $sentences[] = $current;
                    $current = [];
                }

                continue;
            }

            $text = (string) ($segment['text'] ?? '');
            $pieces = preg_split(self::BOUNDARY, $text) ?: [$text];
            $last = count($pieces) - 1;

            foreach ($pieces as $k => $piece) {
                if ($piece !== '') {
                    $current[] = $piece;
                }

                if ($k < $last) {
                    $sentences[] = $current;
                    $current = [];
                }
            }
        }

        $sentences[] = $current;
        $sentences = array_values(array_filter($sentences, fn (array $pieces): bool => self::words($pieces) > 0));

        // Very short fragments join the next sentence (the last one joins the one before it).
        for ($i = 0; $i < count($sentences) && count($sentences) > 1;) {
            if (self::words($sentences[$i]) >= self::MIN_SENTENCE_WORDS) {
                $i++;

                continue;
            }

            if ($i + 1 < count($sentences)) {
                $sentences[$i + 1] = self::joined($sentences[$i], $sentences[$i + 1]);
            } else {
                $sentences[$i - 1] = self::joined($sentences[$i - 1], $sentences[$i]);
            }

            array_splice($sentences, $i, 1);
            $i = max(0, $i - 1);
        }

        return $sentences;
    }

    /**
     * Two neighbouring sentences as one, with a space between them if their own spacing has none.
     *
     * @param  list<string|array<string, mixed>>  $first
     * @param  list<string|array<string, mixed>>  $second
     * @return list<string|array<string, mixed>>
     */
    private static function joined(array $first, array $second): array
    {
        $last = end($first);
        $next = reset($second);
        $spaced = (is_string($last) && preg_match('/\s$/u', $last)) || (is_string($next) && preg_match('/^\s/u', $next));

        return $spaced ? [...$first, ...$second] : [...$first, ' ', ...$second];
    }

    /**
     * @param  array<string, mixed>  $segment
     */
    private static function isQuote(array $segment): bool
    {
        $type = $segment['type'] ?? 'text';

        return $type === 'hadith' || ($type === 'quran' && isset($segment['surah']));
    }

    /**
     * @param  list<string|array<string, mixed>>  $pieces
     */
    private static function words(array $pieces): int
    {
        $text = implode(' ', array_map(fn (string|array $piece): string => is_string($piece) ? $piece : (string) ($piece['text'] ?? ''), $pieces));

        return count(array_filter(preg_split('/\s+/u', trim($text)) ?: [], fn (string $word): bool => preg_match('/[\p{L}\p{N}]/u', $word) === 1));
    }

    /**
     * @return list<mixed>
     */
    private static function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
