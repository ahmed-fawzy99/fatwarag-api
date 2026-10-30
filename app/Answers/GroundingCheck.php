<?php

namespace App\Answers;

use App\Support\Arabic;

/**
 * The safeguard every summary passes before it is shown. It reads the Arabic summary; English summaries
 * are translations of a summary that passed (SummaryTranslator). For each point, preference and caution:
 *
 * - **Support.** The model cites sentence ids, resolved to the stored sentences (SourceSentences), which are the
 *   support quotes. They must appear verbatim (ignoring harakat, letter variants and punctuation) in the answer or the
 *   source's summary of a retrieved fatwa, never in the questioner's words, and say something: at least MIN_QUOTE_WORDS
 *   words in all once opening and closing formulas are removed. An item left without support is dropped.
 * - **Citations.** It cites exactly the retrieved fatwas that its surviving support comes from.
 * - **Grounding.** Each field's content words must appear in its support quotes or cited fatwas, and a good share in
 *   the support quotes themselves (thresholds in `fatwarag.ask`), so a claim can't ride on an unrelated quote.
 * - **Rulings.** Every ruling it states («يجوز», «لا يجوز», «يحرم», «يجب», «لا زكاة فيه»…) appears in its support with
 *   the same polarity, and about the same thing and party: each ruling is bound to its own clause, and no support
 *   clause giving a different ruling may match it better (Polarity::anchored()), so a whole support sentence can't
 *   lend one thing's ruling to another, or one person's duty to another («على الأب» → «على الابن»).
 * - **Numbers.** Every number, in digits or words (fractions included), appears in its own support followed by the
 *   same qualifier («85 جرامًا», «عشرون مثقالًا»).
 * - **Attribution.** No first-person ruling («عندنا», «نرى»); «الراجح», «الصحيح», «الأظهر»… only when attributed to a
 *   named source right there; a preference names its source or mufti; `heldBy` must name a cited source (else null).
 *
 * An ungrounded title is replaced by a neutral label. An item that fails a check here fails whole; only sentences the
 * claims judge names are pruned (prune()), and the rest is checked again here and judged again for what the removal
 * left out (PruneJudge); only what passes is ever shown. «العهدة على المصدر»: one unsupported sentence or item never takes down the rest. When views differ and
 * fewer than two survive, what survived is shown attributed to its source, with a line pointing to the fatwas for the
 * other views (`note`), never as the only view; an agreement whose agreed point fails shows the preferences that
 * passed. Only when nothing survives does the summary fail.
 */
class GroundingCheck
{
    /** Fewest words an item's support (all its sentences together) must have to count, after boilerplate is removed. */
    public const MIN_QUOTE_WORDS = 8;

    /**
     * Share of a title's content words that must appear in its support quotes or body. Titles are a few words, so a
     * short invented title shares words with the body easily; failing it only swaps in a neutral label («القول الثاني»).
     */
    public const TITLE_MIN_SUPPORT = 0.6;

    /** A pruned body or note keeps at least this many content words… */
    public const MIN_PRUNED_WORDS = 6;

    /** …and at least this share of its content words, so pruning never leaves only a side remark. */
    public const MIN_PRUNED_SHARE = 0.5;

    /**
     * A kept sentence rests on a support quote when at least this share of its content words (framing and names
     * aside) are in it; a pruned item shows only the fatwas of the quotes its kept sentences rest on best.
     */
    private const MIN_SENTENCE_SUPPORT = 0.5;

    /** The headline of an agreement whose agreed point failed while preferences passed: neutral framing, never a claim. */
    public const SOURCES_SAY_HEADLINE = 'ما ذكرته المصادر في هذه المسألة';

    /** The fixed line a page adds (never the model's words): a disagreement lost a view, but at least one is shown. */
    public const NOTE_OTHER_VIEWS = 'otherViews';

    /** The fixed line a page adds when something was pruned or dropped, or neither an agreed point nor a view is shown. */
    public const NOTE_DETAILS = 'details';

    /** Words that state a preference; in the site's voice they would pick a side. */
    private const PREFERENCE_WORDS = [
        'الراجح', 'الارجح', 'الاصح', 'الصحيح', 'الصواب', 'ارجح', 'الاظهر', 'الاقوي', 'المختار', 'المعتمد', 'الظاهر',
    ];

    /** The site never speaks in the first person about rulings. */
    private const FIRST_PERSON = ['عندنا', 'نري', 'نرجح', 'نختار', 'نفتي', 'نقول', 'رايي', 'رأينا', 'راينا', 'نراه', 'لدينا', 'عندي', 'اري', 'نميل', 'اميل'];

    /** Verbs that attribute what follows to the source named right after them. */
    private const ATTRIBUTION_VERBS = ['يري', 'تري', 'يرون', 'رجح', 'رجحه', 'يرجح', 'ذكر', 'ذكرت', 'يذكر', 'تذكر', 'نقل', 'نقلت', 'ينقل', 'قال', 'يقول', 'تقول', 'افتي', 'يفتي', 'اختار', 'صحح', 'يصحح', 'قرر', 'يقرر', 'بين', 'يبين', 'اوضح', 'نص'];

    /** Words before a preference word in which the source it's attributed to must be named. */
    private const ATTRIBUTION_WINDOW = 6;

    /**
     * Words with which a fatwa turns from a view it reports to its own («ذهب بعضهم إلى جوازه، والصحيح…»): a report
     * before one of them doesn't carry past it.
     */
    private const OWN_VOICE_TURNS = ['الصحيح', 'الراجح', 'الارجح', 'الاظهر', 'الصواب', 'المختار', 'الاقرب', 'الاصح', 'المعتمد', 'لكن', 'لكنه', 'عندنا', 'نري', 'نرجح', 'نختار', 'نفتي'];

    /** At most this many words of a fatwa reach the entailment judge per quote: the quote and its own clause around it. */
    public const JUDGE_CONTEXT_WORDS = 40;

    /**
     * Wording that reports a view as someone else's rather than the fatwa's own: «ذهب بعضهم إلى», «قال بعض العلماء»,
     * «وقيل», «ومنهم من», «وذهب الجمهور», «في قول», "some held", "it is said". Matched on normalized text.
     */
    private const OTHERS_VIEW_PATTERNS = [
        '/\s[وف]?(قيل|زعم|يزعم|زعموا|يزعمون)\s/u',
        '/\s[وف]?(ذهب|يذهب|ذهبوا|قال|يقول|يقولون|قالوا|ذكر|يذكر|ذكرت|نقل|ينقل|حكي|يحكي|يري|يرون|راي|راوا|اختار|اجاز|اجازه|منع|منعه|حرم|حرمه|كره|كرهه|اوجب|اوجبه|افتي|استحب|استحبه)\s+(\S+\s+){0,2}[وف]?(بعض|بعضهم|البعض|اخرون|اخرين|جماعه|طايفه|فريق|قوم|كثير|اكثر|الجمهور|جمهور)\s/u',
        '/\s[وف]?(ذهب|يذهب|ذهبوا)\s+(\S+\s+){0,3}(الي|اليه)\s/u',
        '/\s[وف]?منهم\s+من\s/u',
        '/\sمن\s+(العلماء|الفقهاء|السلف|اهل\s+العلم)\s+من\s/u',
        '/\s(عند|لدي|في\s+قول)\s+(بعض|بعضهم|البعض|جمع|جماعه|طايفه|فريق|كثير|كثيرين|اكثر|اغلب|عامه|جمهور|الجمهور)\s/u',
        '/\s[وف]?(بعض|جمع|جماعه|طايفه|فريق|كثير|عدد|اكثر)\s+من\s+(اهل|العلماء|الفقهاء|السلف|الصحابه|التابعين|الايمه|المعاصرين|الحنفيه|المالكيه|الشافعيه|الحنابله|المتاخرين|المتقدمين)\s/u',
        '/\s[وف]?(في|علي)\s+قول\s/u',
        '/\s(القول|قول|الراي|راي)\s+(الاخر|اخر)\s/u',
        '/\s[وف]?(هو|هذا|وهذا|انه|انها)\s+(قول|مذهب)\s/u',
        '/\s[وف]?(المذهب|مذهب)\s/u',
        '/\s(some|other|many)\s+(of\s+the\s+)?(scholars|jurists|people|ulama|fuqaha|imams)\s/u',
        '/\s(some|others)\s+(hold|held|say|said|argue|argued|view|viewed|believe|believed|maintain|maintained|are\s+of|were\s+of)\s/u',
        '/\sit\s+(is|was|has\s+been)\s+said\s/u',
        '/\saccording\s+to\s+some\s/u',
    ];

    private NumberReader $numbers;

    private Polarity $polarity;

    /** @var array<string, array<string, true>> */
    private array $sourceStems = [];

    /** @var array<string, string> */
    private array $rawSources = [];

    /** @var array<string, string> normalized texts, without the question */
    private array $normalizedSources = [];

    /** @var array<string, list<string>> */
    private array $names = [];

    /**
     * Whether the claims judge rules on every item this check passes. Then the heuristic ruling checks (numbers, ruling
     * strength, anchoring, conditions, time, a cited fatwa's other ruling, reported views) don't veto: they misread
     * correct paraphrases, and «العهدة على المصدر»: relaying what a fatwa says, views it reports included, is the job.
     * The judge reads meaning. What they flag is kept in `deferred()` for the logs. The structural checks (support
     * quotes, wording coverage, the site's own voice, naming the source) still reject.
     */
    private bool $judged = false;

    /** @var list<array{item: string, reason: string}> */
    private array $deferred = [];

    /** @var list<array{item: string, reason: string}> */
    private array $rejections = [];

    public function __construct(?NumberReader $numbers = null, private ?float $minCoverage = null, private ?float $minSupportCoverage = null)
    {
        $this->numbers = $numbers ?? new NumberReader;
        $this->polarity = new Polarity;
    }

    /**
     * @param  array<string, mixed>  $draft  the model's output: kind, headline, agreed, views, preferences, caution
     * @param  array<string, string>  $sources  full text of each retrieved fatwa, by public id
     * @param  array<string, list<string>>  $names  the names a fatwa can be attributed by (source, mufti), by public id
     * @return array<string, mixed>|null the draft with failing items removed, or null when nothing usable survives
     */
    public function check(array $draft, array $sources, string $locale, array $names = []): ?array
    {
        $this->prepare($sources, $names);

        $views = $this->listOf($draft['views'] ?? []);
        $agreed = $this->item('agreed', $draft['agreed'] ?? null);
        $checkedViews = array_map(fn (mixed $view, int $i): ?array => $this->item('view', $view, 'view '.($i + 1)), $views, array_keys($views));
        $preferences = array_map(fn (mixed $preference): ?array => $this->item('preference', $preference), $this->listOf($draft['preferences'] ?? []));
        $caution = $this->item('caution', $draft['caution'] ?? null);
        // What was drafted for the sections it shows (an agreement shows no views or caution), to tell whether any was lost.
        $differ = ($draft['kind'] ?? null) === 'differ';
        $drafted = [($draft['agreed'] ?? null) !== null ? $agreed : [], ...$preferences, ...($differ ? [...$checkedViews, ($draft['caution'] ?? null) !== null ? $caution : []] : [])];
        $lost = in_array(null, $drafted, true) || array_filter($drafted, fn (?array $item): bool => ! empty($item['_pruned'])) !== [];

        return $this->finish($draft, $agreed, $this->keep($checkedViews), $this->keep($preferences), $caution, count($views), $lost);
    }

    /**
     * Sets the fatwas the next items are checked against, and starts a new list of rejections.
     *
     * @param  array<string, string>  $sources  full text of each retrieved fatwa, by public id
     * @param  array<string, list<string>>  $names  the names a fatwa can be attributed by (source, mufti), by public id
     */
    public function prepare(array $sources, array $names = [], bool $judged = false): void
    {
        $this->judged = $judged;
        $this->deferred = [];
        $this->normalizedSources = array_map(fn (string $text): string => Arabic::normalize(self::withoutQuestion($text)), $sources);
        $this->rawSources = $sources;
        $this->names = $names;
        $this->polarity->ignoring(array_merge(...array_values($names) ?: [[]]));
        $this->sourceStems = [];
        $this->rejections = [];
    }

    /**
     * One item checked on its own (after `prepare()`), so a streamed summary can check each item as it arrives.
     *
     * @param  'agreed'|'view'|'preference'|'caution'  $section
     * @return array<string, mixed>|null the checked item, or null when it fails
     */
    public function item(string $section, mixed $item, ?string $label = null): ?array
    {
        $isPoint = in_array($section, ['agreed', 'view'], true);
        $fields = $isPoint ? ['prose' => ['title', 'body'], 'heldBy' => true] : ['prose' => ['text'], 'heldBy' => false];
        $label ??= is_array($item) ? trim((string) ($item['title'] ?? $item['text'] ?? $section)) : $section;

        // Never pruned here: an item whose text fails a lexical check fails whole, as it always did. Only the claims
        // judge's named sentences are ever pruned (prune()), and every prune is judged again for what it left out.
        return $this->verifyItem($item, $fields, $this->normalizedSources, $this->names, $label, mustName: $section === 'preference');
    }

    /**
     * A checked item with some sentences of its body (or note) removed, as the claims judge numbered them (1-based),
     * and checked again whole: null when nothing meaningful is left (core()) or what is left fails any check.
     *
     * @param  'agreed'|'view'|'preference'|'caution'  $section
     * @param  array<string, mixed>  $checked  an item this check passed
     * @param  list<int>  $numbers
     * @return array<string, mixed>|null
     */
    public function prune(string $section, array $checked, array $numbers): ?array
    {
        $isPoint = in_array($section, ['agreed', 'view'], true);
        $fields = $isPoint ? ['prose' => ['title', 'body'], 'heldBy' => true] : ['prose' => ['text'], 'heldBy' => false];
        $field = $isPoint ? 'body' : 'text';
        $sentences = self::sentences((string) ($checked[$field] ?? ''));
        $drop = array_values(array_unique(array_intersect(array_map(fn (mixed $number): int => (int) $number - 1, $numbers), array_keys($sentences))));

        if ($drop === [] || count($sentences) < 2) {
            return null;
        }

        $keep = array_values(array_diff(array_keys($sentences), $drop));

        if (! $this->saysSomething($sentences, $keep)) {
            return null;
        }

        $before = $this->rejections;
        $item = [...array_diff_key($checked, ['_relabel' => true, '_pruned' => true]), $field => self::joined($sentences, $keep)];
        $label = trim((string) ($checked['title'] ?? $checked['text'] ?? $section));
        $pruned = $this->verifyItem($item, $fields, $this->normalizedSources, $this->names, $label, mustName: $section === 'preference');
        $this->rejections = $before;

        if ($pruned === null || ! $this->core($sentences, $keep, $pruned)) {
            return null;
        }

        $pruned['_pruned'] = (int) ($checked['_pruned'] ?? 0) + count($drop);
        $pruned['_removed'] = array_values(array_map(fn (int $i): string => $sentences[$i], $drop));

        return $this->withShownCitations($pruned, $field);
    }

    /**
     * A pruned item's shown citations: each kept sentence (the title never counts) rests on the support quotes that
     * hold the most of its content words, framing and names aside, and at least MIN_SENTENCE_SUPPORT of them; only
     * those quotes' fatwas are shown, so a fatwa that backed only a removed sentence, or that shares only a stock
     * phrase («عند جميع أهل العلم»), isn't. Its evidence goes with it (SummaryWriter::evidence()). Every check still
     * reads the whole support. Null when a kept sentence rests on no quote: the prune fails.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function withShownCitations(array $item, string $field): ?array
    {
        $ignored = array_fill_keys([...Lexicon::FRAMING, ...Lexicon::contentStems(implode(' ', array_merge(...array_values($this->names) ?: [[]])))], true);
        $quoteStems = array_map(fn (array $quote): array => array_fill_keys(Lexicon::contentStems($quote['quote']), true), $item['support']);
        $resting = [];

        foreach (self::sentences((string) ($item[$field] ?? '')) as $sentence) {
            $stems = array_values(array_filter(array_unique(Lexicon::contentStems($sentence)), fn (string $stem): bool => ! isset($ignored[$stem])));

            if ($stems === []) {
                continue;
            }

            $shares = array_map(fn (array $quote): float => count(array_filter($stems, fn (string $stem): bool => isset($quote[$stem]))) / count($stems), $quoteStems);
            $best = max($shares);

            if ($best < self::MIN_SENTENCE_SUPPORT) {
                return null;
            }

            foreach ($shares as $k => $share) {
                if ($share === $best) {
                    $resting[$item['support'][$k]['fatwaId']] = true;
                }
            }
        }

        $shown = array_values(array_filter($item['citations'], fn (string $id): bool => isset($resting[$id])));

        if ($shown === []) {
            return null;
        }

        // A title that rests on what was removed («الجمع للمسافر وبيع الذهب») gives way to the neutral label: it is
        // judged against the kept text and the quotes of the fatwas still shown only.
        if (filled($item['title'] ?? null) && empty($item['_relabel'])) {
            $kept = [(string) ($item[$field] ?? ''), ...array_column(array_filter($item['support'], fn (array $quote): bool => in_array($quote['fatwaId'], $shown, true)), 'quote')];

            if ($this->coverage((string) $item['title'], $kept, [], $this->normalizedSources, array_merge(...array_values($this->names) ?: [[]]))['support'] < self::TITLE_MIN_SUPPORT) {
                $item['_relabel'] = true;
            }
        }

        return [...$item, 'citations' => $shown];
    }

    /**
     * Whether the kept sentences still say something: at least MIN_PRUNED_WORDS content words and MIN_PRUNED_SHARE of
     * the whole text's, and a ruling when the whole text stated one (never just its evidence or a lead-in).
     *
     * @param  list<string>  $sentences
     * @param  list<int>  $keep
     */
    private function saysSomething(array $sentences, array $keep): bool
    {
        $kept = self::joined($sentences, $keep);
        $words = count(Lexicon::contentStems($kept));

        return $words >= self::MIN_PRUNED_WORDS
            && $words >= self::MIN_PRUNED_SHARE * count(Lexicon::contentStems(implode(' ', $sentences)))
            && ($this->polarity->rulings($kept) !== [] || $this->polarity->rulings(implode(' ', $sentences)) === []);
    }

    /**
     * Whether a pruned item keeps its main ruling: its lead sentence is kept, or its own title, which states a ruling,
     * still passes its check against what is left (it isn't relabelled).
     *
     * @param  list<string>  $sentences
     * @param  list<int>  $keep
     * @param  array<string, mixed>  $checked  the pruned item as it passed
     */
    private function core(array $sentences, array $keep, array $checked): bool
    {
        if (in_array($this->leadSentence($sentences), $keep, true)) {
            return true;
        }

        return empty($checked['_relabel']) && filled($checked['title'] ?? null) && $this->polarity->rulings((string) $checked['title']) !== [];
    }

    /**
     * The sentence carrying a point's main ruling: the first that states one, else the first.
     *
     * @param  list<string>  $sentences
     */
    private function leadSentence(array $sentences): int
    {
        foreach ($sentences as $i => $sentence) {
            if ($this->polarity->rulings($sentence) !== []) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * @param  list<string>  $sentences
     * @param  list<int>  $keep
     */
    private static function joined(array $sentences, array $keep): string
    {
        sort($keep);

        return implode(' ', array_map(fn (int $i): string => $sentences[$i], $keep));
    }

    /**
     * A summary text's sentences, split after «.», «!», «?», «؟» or a line break followed by a space (so «3.5» stays
     * whole), never inside a «quotation» and never at «؛», whose clause belongs to its sentence.
     *
     * @return list<string>
     */
    public static function sentences(string $text): array
    {
        $chars = mb_str_split($text);
        $sentences = [];
        $current = '';
        $depth = 0;

        foreach ($chars as $k => $char) {
            $current .= $char;

            if ($char === '«') {
                $depth++;
            } elseif ($char === '»') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && in_array($char, ['.', '!', '?', '؟', "\n"], true) && (! isset($chars[$k + 1]) || preg_match('/\s/u', $chars[$k + 1]))) {
                $sentences[] = trim($current);
                $current = '';
            }
        }

        $sentences[] = trim($current);

        return array_values(array_filter($sentences, fn (string $sentence): bool => Lexicon::tokens($sentence) !== []));
    }

    /**
     * The summary from its checked items: a disagreement with fewer than two views fails (or, when the draft had fewer
     * than two views and an agreed point, becomes an agreement), an agreement shows no views or caution, ungrounded
     * titles get neutral labels and the headline is checked last.
     *
     * @param  array<string, mixed>  $draft  kind and headline
     * @param  array<string, mixed>|null  $agreed
     * @param  list<array<string, mixed>>  $views
     * @param  list<array<string, mixed>>  $preferences
     * @param  array<string, mixed>|null  $caution
     * @param  int  $draftViews  how many views the draft had before any was dropped
     * @param  bool  $lost  whether any of its items (of the sections it shows) was pruned or dropped
     * @param  bool  $detailed  the writer's flag that the fatwas hold details the summary doesn't spell out; it only adds the note
     * @return array<string, mixed>|null with `note`: NOTE_OTHER_VIEWS, NOTE_DETAILS or null (never both)
     */
    public function finish(array $draft, ?array $agreed, array $views, array $preferences, ?array $caution, int $draftViews, bool $lost = false, bool $detailed = false): ?array
    {
        $kind = ($draft['kind'] ?? null) === 'differ' ? 'differ' : 'agree';
        $partial = false;
        $lostView = false;

        if ($kind === 'differ' && count($views) < 2) {
            if ($draftViews < 2 && $agreed !== null) {
                // The draft gave fewer than two views beside an agreed point: an agreement, as it always was.
                $kind = 'agree';
                $views = [];
            } else {
                // One side (or none) survived: what survived is shown attributed, with a line pointing to the fatwas
                // for the other views, never as the only view.
                $views = array_values(array_filter($views, $this->namesWhoseView(...)));
                $partial = true;
                $lostView = true;

                if ($agreed === null && $views === [] && $preferences === [] && $caution === null) {
                    $this->rejections[] = ['item' => 'summary', 'reason' => 'no view, agreed point, preference or caution of this disagreement survived'];

                    return null;
                }
            }
        }

        if ($kind === 'agree') {
            $views = [];
            $caution = null;

            if ($agreed === null && $preferences === []) {
                $this->rejections[] = ['item' => 'summary', 'reason' => 'the agreed point failed and no preference survived'];

                return null;
            }

            // Preferences alone: a line points to the fatwas for the details.
            $partial = $agreed === null;
        }

        [$agreed, $views] = $this->relabel($agreed, $views);
        $survivors = array_values(array_filter([$agreed, ...$views, ...$preferences, $caution]));
        // Only part of the sources' answer passed: a neutral headline, never «اختلفت المصادر» over what wasn't verified.
        $headline = $partial
            ? self::SOURCES_SAY_HEADLINE
            : $this->headline($draft, $survivors, $kind, $agreed, $this->normalizedSources, $this->names, count($views));

        return [
            'kind' => $kind,
            'headline' => $headline,
            'agreed' => $agreed,
            'views' => $views,
            'preferences' => $preferences,
            'caution' => $caution,
            // A disagreement that lost a view, with one shown: «وللمسألة أقوال أخرى…». Else, when anything was pruned or
            // dropped, or neither an agreed point nor a view is shown: «وتفصيل المسألة في الفتاوى أدناه».
            'note' => match (true) {
                $lostView && $views !== [] && $draftViews >= 2 => self::NOTE_OTHER_VIEWS,
                $lost || $lostView || $detailed || ($agreed === null && $views === []) => self::NOTE_DETAILS,
                default => null,
            },
        ];
    }

    /**
     * A checked point as it shows at its place: a title that failed its check becomes the neutral label for that place
     * («ما اتفقت عليه المصادر», «القول الثاني»).
     *
     * @param  array<string, mixed>  $point
     * @param  int|null  $viewIndex  null for the agreed point
     * @return array<string, mixed>
     */
    public static function labelled(array $point, ?int $viewIndex): array
    {
        $ordinals = ['الأول', 'الثاني', 'الثالث', 'الرابع', 'الخامس', 'السادس'];

        if (! empty($point['_relabel'])) {
            $point['title'] = $viewIndex === null ? 'ما اتفقت عليه المصادر' : 'القول '.($ordinals[$viewIndex] ?? (string) ($viewIndex + 1));
        }

        unset($point['_relabel'], $point['_pruned'], $point['_removed']);

        return $point;
    }

    /**
     * Whether a view says whose it is: a `heldBy` that passed its check, or a source, mufti or group of scholars named
     * in its own words («يرى الشيخ ابن باز…», «ذهب جمهور العلماء…»). Only such a view may stand as the one side shown.
     *
     * @param  array<string, mixed>  $view
     */
    public function namesWhoseView(array $view): bool
    {
        $text = trim((string) ($view['title'] ?? '').' '.(string) ($view['body'] ?? ''));

        $names = array_merge(...array_values($this->names) ?: [[]]);
        $tokens = Lexicon::tokens($text);
        // A name counts only right after an attribution verb or «عند» («يرى ابن باز», «عند إسلام ويب»), not «خلافًا لابن باز».
        $named = array_filter(array_keys($tokens), fn (int $j): bool => (in_array((string) preg_replace('/^(و|ف)(?=..)/u', '', $tokens[$j]), self::ATTRIBUTION_VERBS, true)
            || in_array($tokens[$j], ['عند', 'لدي'], true)) && $this->mentions(implode(' ', array_slice($tokens, $j + 1, 5)), $names));

        return filled($view['heldBy'] ?? null)
            || $named !== []
            || self::attributesToOthers($text)
            || preg_match(self::MAJORITY_PATTERN, ' '.Arabic::normalize($text).' ') === 1;
    }

    /**
     * Share of the text's content words found in the pool of support quotes and cited fatwas.
     *
     * @param  list<string>  $supportQuotes
     * @param  list<string>  $citedIds
     * @param  array<string, string>  $sources  normalized texts
     * @param  list<string>  $names  source and mufti names, which attribute rather than claim
     * @return array{all: float, support: float}
     */
    public function coverage(string $prose, array $supportQuotes, array $citedIds, array $sources, array $names = []): array
    {
        $ignored = array_fill_keys([...Lexicon::FRAMING, ...Lexicon::contentStems(implode(' ', $names))], true);
        $words = array_values(array_filter(array_unique(Lexicon::contentStems($prose)), fn (string $stem): bool => ! isset($ignored[$stem])));

        if ($words === []) {
            return ['all' => 1.0, 'support' => 1.0];
        }

        $supportStems = array_fill_keys(Lexicon::contentStems(implode(' ', $supportQuotes)), true);
        $inSupport = 0;
        $inAll = 0;

        foreach ($words as $word) {
            if (isset($supportStems[$word])) {
                $inSupport++;
                $inAll++;

                continue;
            }

            foreach ($citedIds as $id) {
                if (isset($this->stemsOf($id, $sources)[$word])) {
                    $inAll++;
                    break;
                }
            }
        }

        return ['all' => $inAll / count($words), 'support' => $inSupport / count($words)];
    }

    /**
     * @param  array{prose: list<string>, heldBy: bool}  $fields
     * @param  array<string, string>  $sources  normalized texts
     * @param  array<string, list<string>>  $names
     * @return array<string, mixed>|null
     */
    private function verifyItem(mixed $item, array $fields, array $sources, array $names, string $label, bool $mustName = false): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        $reject = function (string $reason) use ($label): null {
            $this->rejections[] = ['item' => $label, 'reason' => $reason];

            return null;
        };

        // A heuristic ruling check: rejects, unless the claims judge rules on the item (then it is only noted).
        $heuristic = function (string $reason) use ($label): bool {
            if ($this->judged) {
                $this->deferred[] = ['item' => $label, 'reason' => $reason];

                return false;
            }

            $this->rejections[] = ['item' => $label, 'reason' => $reason];

            return true;
        };

        $support = $this->validSupport($item, $sources);

        if ($support === []) {
            return $reject('no support: cite the sentences (8+ meaningful words in all) of a cited fatwa\'s answer that state it');
        }

        $proposed = array_map('strval', $this->listOf($item['citations'] ?? []));
        $supported = array_values(array_unique(array_column($support, 'fatwaId')));
        $citations = [...array_values(array_intersect($proposed, $supported)), ...array_values(array_diff($supported, $proposed))];

        $allNames = array_merge(...array_values($names) ?: [[]]);
        $heldBy = $fields['heldBy'] ? trim((string) ($item['heldBy'] ?? '')) : '';
        $claimText = trim(implode(' ', array_map(fn (string $field): string => (string) ($item[$field] ?? ''), array_values(array_diff($fields['prose'], ['title'])))));
        $carrierNames = array_merge(...array_map(fn (string $id): array => $names[$id] ?? [], $this->carriers($support, $claimText, $sources, $allNames)) ?: [[]]);

        // Who holds it must be the source or mufti of a fatwa whose own cited sentences state it, not any cited fatwa.
        if ($heldBy !== '' && ! $this->namesOnly($heldBy, $carrierNames, $allNames)) {
            $heldBy = '';
        }

        // A view the fatwa reports from others («ذهب بعضهم إلى جوازه، والصحيح…») is not its mufti's view.
        if ($heldBy !== '' && $this->reportsOthersView($support, $heldBy, $names)) {
            $heldBy = '';
        }

        $quotes = array_column($support, 'quote');
        $proseFields = array_values(array_diff($fields['prose'], ['title']));

        // A title that isn't grounded is replaced by a neutral label («القول الثاني») rather than dropping a sound point;
        // see relabel(). Titles are a few words, so they are checked against the support quotes and the body.
        $titleFailed = false;

        if (array_key_exists('title', $item) && in_array('title', $fields['prose'], true)) {
            $title = (string) $item['title'];
            $titleCoverage = $this->coverage($title, [...$quotes, (string) ($item['body'] ?? '')], $citations, $sources, $allNames);
            $titleFailed = ! $this->grounded($titleCoverage) || $titleCoverage['support'] < self::TITLE_MIN_SUPPORT
                || ! $this->numbersSupported($title, $quotes, qualifiers: true)
                || ! $this->polarity->consistent($title, $quotes)
                || ! $this->polarity->anchored($title, $quotes)
                || $this->speaksForItself($title, $allNames);
        }

        foreach ($proseFields as $field) {
            $text = trim((string) ($item[$field] ?? ''));

            if ($text === '') {
                return $reject("empty {$field}");
            }

            // Field by field, so a well-grounded title can't carry an invented body.
            $coverage = $this->coverage($text, $quotes, $citations, $sources, $allNames);

            if (! $this->grounded($coverage) && $this->onlySupportShareFails($coverage)) {
                // The quote may be a fragment of the sentence the point paraphrases: read the whole sentence instead.
                $coverage = $this->coverage($text, $this->widened($support), $citations, $sources, $allNames);
            }

            if (! $this->grounded($coverage)) {
                return $reject(sprintf('%s wording not grounded in its quotes (%.0f%% in quotes or cited fatwas, %.0f%% in quotes)', $field, $coverage['all'] * 100, $coverage['support'] * 100));
            }
        }

        $prose = trim(implode(' ', array_map(fn (string $field): string => (string) ($item[$field] ?? ''), $proseFields)));
        $proseParts = [...array_map(fn (string $field): string => (string) ($item[$field] ?? ''), $proseFields), $heldBy];

        if (! $this->allNumbersSupported($proseParts, $quotes, qualifiers: true) && $heuristic('a number (or its unit) is not in its own support quotes')) {
            return null;
        }

        if ($this->speaksForItself($prose, $allNames)) {
            return $reject('states a preference in the site\'s own voice or without naming the source next to it');
        }

        foreach ($proseParts as $part) {
            if (! $this->polarity->consistent($part, $quotes) && $heuristic('states a ruling (or its strength/negation) that its own support quotes do not state; quote the sentence that states the ruling')) {
                return null;
            }

            if (! $this->polarity->anchored($part, $quotes) && $heuristic('states a ruling for something its support gives a different ruling for; keep each ruling with what the fatwa says it about')) {
                return null;
            }
        }

        if ($mustName && ! $this->namesOnly($prose, $carrierNames, $allNames)) {
            return $reject('preference does not name the source or mufti whose own cited sentences state it');
        }

        $reportsOthers = self::attributesToOthers(implode(' ', [(string) ($item['title'] ?? ''), $claimText, (string) ($item['heldBy'] ?? '')]));

        // A point whose ruling is carried only by a view its fatwa reports from others («ذكر بعض الفقهاء أقوالا…
        // وأشهرها: يجوز…») would reach readers as that fatwa's ruling, unless the point itself says it is others' view.
        if ($fields['heldBy'] && ! $reportsOthers) {
            if (! $this->carriedByOwnVoice($claimText, $support) && $heuristic('its ruling rests only on a view the fatwa reports from others («ذهب بعضهم», «منها:», «وأشهرها:»); say whose view it is, or cite the sentence where the fatwa states its own view')) {
                return null;
            }

            if (array_key_exists('title', $item) && ! $this->carriedByOwnVoice((string) $item['title'], $support)) {
                $titleFailed = true;
            }
        }

        // With the claims judge off, these carry what it used to catch: a ruling keeps the condition its sentence
        // attaches to it («إذا جد به السير», «عند خوف النسيان», «بإذن زوجها») and its comparatives («فأكثر»).
        if (! $this->keepsConditions($claimText, $support) && $heuristic('drops or changes a condition its sentence attaches to the ruling («إذا…», «إلا…», «بشرط…», «بإذن…»); keep the condition and the case it applies to')) {
            return null;
        }

        // A ruling's time or frequency must be its support's («كل شهر» for «كل عام»).
        if ($this->changesTime($claimText, $quotes) && $heuristic('a ruling\'s time or frequency is not in its own support; keep it as the fatwa gives it')) {
            return null;
        }

        // A cited fatwa that gives a different ruling on the same thing in sentences not cited contradicts it. A point
        // saying it reports others' view («ذهب بعض العلماء…») may cite a fatwa that then rejects that view.
        foreach ($reportsOthers ? [] : $citations as $fatwaId) {
            $fatwaText = self::withoutQuestion($this->rawSources[$fatwaId] ?? '');

            if ($this->polarity->contradictedBy($claimText, $fatwaText) && $heuristic('a cited fatwa gives a different ruling on the same thing; cite the sentence that states its ruling on it')) {
                return null;
            }

            if (array_key_exists('title', $item) && $this->polarity->contradictedBy((string) $item['title'], $fatwaText)) {
                $titleFailed = true;
            }
        }

        if (array_key_exists('title', $item) && $this->changesTime((string) $item['title'], $quotes)) {
            $titleFailed = true;
        }

        if ($mustName && $this->reportsOthersView($support, $prose, $names) && $heuristic('preference credits the source with a view its quote reports from others («ذهب بعضهم», «وقيل»); quote the sentence where the source states its own view')) {
            return null;
        }

        $checked = [...$item, 'citations' => $citations, 'support' => $support];

        if ($fields['heldBy']) {
            $checked['heldBy'] = $heldBy !== '' ? $heldBy : null;
        }

        if ($titleFailed) {
            $checked['_relabel'] = true;
        }

        return $checked;
    }

    /**
     * @param  array{all: float, support: float}  $coverage
     */
    private function onlySupportShareFails(array $coverage): bool
    {
        return $coverage['all'] >= $this->thresholds()[0] && $coverage['support'] < $this->thresholds()[1];
    }

    /**
     * Each support quote widened to the whole sentence(s) of the answer it was taken from.
     *
     * @param  list<array{fatwaId: string, quote: string}>  $support
     * @return list<string>
     */
    private function widened(array $support): array
    {
        return array_map(fn (array $quote): string => self::sentencesAround($this->rawSources[$quote['fatwaId']] ?? '', $quote['quote']), $support);
    }

    /**
     * Whether a support quote from a fatwa the text (a `heldBy` or a preference) names reports its view as others'.
     *
     * @param  list<array{fatwaId: string, quote: string}>  $support
     * @param  array<string, list<string>>  $names
     */
    private function reportsOthersView(array $support, string $naming, array $names): bool
    {
        foreach ($support as $quote) {
            if ($this->mentions($naming, $names[$quote['fatwaId']] ?? []) && $this->isReported($quote)) {
                return true;
            }
        }

        return false;
    }

    /** Words with which a sentence introduces a list of views («ذكر الفقهاء أقوالا…», «منها:», «وأشهرها:»). */
    private const VIEW_LIST_PATTERN = '/(^|\s)[وف]?(اقوال|اقوالا|الاقوال|اقوالهم|اراء|الاراء|ارايهم|مذاهب|المذاهب)(\s|$)/u';

    /** A list intro right before a colon. */
    private const LIST_INTRO_PATTERN = '/(^|\s)[وف]?(منها|اشهرها|اهمها|ابرزها|اولها|ثانيها|احدها)\s*:/u';

    /** Wording with which the fatwa turns away from what it just reported («والراجح عندنا خلاف ذلك»). */
    private const OTHERWISE_PATTERN = '/(^|\s)(خلاف|بخلاف|عكس|بعكس)\s+(ذلك|هذا|ذاك)(\s|$)|(^|\s)[وف]?(بخلافه|خلافه)(\s|$)/u';

    /** A view held by most scholars («عند الجمهور», «ذهب جمهور العلماء»), which a fatwa often adopts as its own. */
    private const MAJORITY_PATTERN = '/(^|\s)[وف]?(ال)?(جمهور|جماهير)(\s|$)|(^|\s)(اكثر|اغلب|عامه)\s+(اهل\s+العلم|العلماء|الفقهاء)(\s|$)/u';

    /** Words with which a view is a few scholars' («ذهب بعضهم», «وقيل», «عند جمع من أهل العلم»); not «بعض الصلوات». */
    private const MINORITY_PATTERN = '/(^|\s)[وف]?(بعضهم|البعض|اخرون|اخرين|قيل|زعم|طايفه|فريق|قوم|منهم\s+من)(\s|$)|(^|\s)[وف]?(بعض|جمع|جماعه|كثير)\s+(من\s+)?(العلماء|اهل|الفقهاء|السلف|الايمه|المعاصرين|الصحابه|الحنفيه|المالكيه|الشافعيه|الحنابله)(\s|$)/u';

    /**
     * Whether a support quote gives a view its fatwa reports from others rather than its own:
     * - its own sentence reports it (reportedView()), unless it only says most scholars hold it («ولا يجوز… عند
     *   الجمهور») and the fatwa never turns against it: a majority view the fatwa adopts is its own;
     * - a sentence before it in its paragraph, back to the fatwa's last turn to its own voice, reports a view or
     *   introduces a list of views («ذهب بعض أهل العلم…» four sentences back, «ذكر الفقهاء أقوالا… وأشهرها:»);
     * - later in the fatwa (any paragraph) the fatwa turns to its own voice against it (contraryTurn()).
     *
     * @param  array{fatwaId: string, quote: string, before?: list<string>, after?: list<string>}  $quote
     */
    private function isReported(array $quote): bool
    {
        $normalized = Arabic::normalize($quote['quote']);

        if (self::reportedView($this->rawSources[$quote['fatwaId']] ?? '', $quote['quote'])) {
            $majorityOnly = preg_match(self::MAJORITY_PATTERN, $normalized) === 1 && preg_match(self::MINORITY_PATTERN, $normalized) !== 1;

            if (! $majorityOnly || $this->contraryTurn($quote) !== null) {
                return true;
            }
        }

        if (preg_match(self::LIST_INTRO_PATTERN, $normalized.' ') === 1) {
            // «وأشهرها: …» names one of the views just listed.
            foreach ($quote['before'] ?? [] as $sentence) {
                if (preg_match(self::VIEW_LIST_PATTERN, Arabic::normalize($sentence)) === 1 || self::attributesToOthers($sentence)) {
                    return true;
                }
            }
        } elseif (! self::opensOwnVoice($quote['quote'])) {
            foreach (array_reverse($quote['before'] ?? []) as $sentence) {
                if (self::opensOwnVoice($sentence)) {
                    break;
                }

                if (self::attributesToOthers($sentence) || preg_match(self::VIEW_LIST_PATTERN, Arabic::normalize($sentence)) === 1 || preg_match(self::LIST_INTRO_PATTERN, Arabic::normalize($sentence).' ') === 1) {
                    return true;
                }
            }
        }

        return $this->contraryTurn($quote) !== null;
    }

    /**
     * The sentence, anywhere later in the fatwa, where it turns to its own voice against what the quote says
     * («والراجح عندنا خلاف ذلك», «والصحيح أنه لا يجوز…» on the same subject), or null. A report in between («ذهب
     * آخرون…») means a later turn answers that report, and a turn that agrees ends the search. A quote that itself
     * speaks in the fatwa's own voice has none.
     *
     * @param  array{quote: string, after?: list<string>}  $quote
     */
    public function contraryTurn(array $quote): ?string
    {
        if (self::opensOwnVoice($quote['quote'])) {
            return null;
        }

        $rulings = $this->polarity->rulings($quote['quote']);
        $stems = array_flip(Lexicon::contentStems($quote['quote']));

        foreach ($quote['after'] ?? [] as $sentence) {
            // A verdict («والراجح…», «والصحيح…»), not a mere «لكن».
            if (! self::opensOwnVoice($sentence, verdictOnly: true)) {
                if (self::attributesToOthers($sentence) || preg_match(self::VIEW_LIST_PATTERN, Arabic::normalize($sentence)) === 1) {
                    return null;
                }

                continue;
            }

            if (preg_match(self::OTHERWISE_PATTERN, ' '.Arabic::normalize($sentence).' ') === 1) {
                return $sentence;
            }

            // On the same subject: at least three of the quote's words.
            if (count(array_intersect_key(array_flip(Lexicon::contentStems($sentence)), $stems)) >= 3) {
                return $this->polarity->opposed($this->polarity->rulings($sentence), $rulings) ? $sentence : null;
            }
        }

        return null;
    }

    /**
     * Whether a sentence opens in the fatwa's own voice («والراجح…», «والصحيح أنه…», «لكن الأظهر…»).
     */
    private static function opensOwnVoice(string $sentence, bool $verdictOnly = false): bool
    {
        foreach (array_slice(Lexicon::tokens($sentence), 0, 4) as $token) {
            $bare = (string) preg_replace('/^(و|ف)(?=..)/u', '', $token);

            if (in_array($bare, self::OWN_VOICE_TURNS, true) && ! ($verdictOnly && in_array($bare, ['لكن', 'لكنه'], true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether each ruling the text states is carried, on its subject, by a support quote in the fatwa's own voice: the
     * quote stating it that shares most of its words is not a reported view.
     *
     * @param  list<array{fatwaId: string, quote: string}>  $support
     */
    private function carriedByOwnVoice(string $text, array $support): bool
    {
        foreach ($this->polarity->rulingScopes($text) as ['value' => $value, 'scope' => $scope]) {
            $best = 0;
            $bestOwn = 0;
            $ownWords = [];
            $reportedWords = [];

            foreach ($support as $quote) {
                $own = $this->ownVoice($quote);
                $best = max($best, $this->polarity->stated($value, $scope, $quote['quote']));
                $bestOwn = max($bestOwn, $this->polarity->stated($value, $scope, $own));
                $ownWords += array_flip(Lexicon::contentStems($own));

                if ($own !== $quote['quote']) {
                    $reportedWords += array_flip($this->reportedRulingWords($quote['quote'], $value));
                }
            }

            // The own-voice sentence must be on the ruling's subject: as close as the best match, or half its words.
            if ($best > 0 && $bestOwn < min($best, max(2, (int) ceil(count($scope) / 2)))) {
                return false;
            }

            // And no word of the subject may come only from a reported view stating the ruling («من المصحف بحائل»
            // from «ذهب بعض العلماء إلى أنه يجوز…» beside the fatwa's own «يجوز… من حفظها»).
            if ($bestOwn > 0 && array_diff_key(array_intersect_key(array_flip($scope), $reportedWords), $ownWords) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * The words of the clauses of a quote that state this ruling as others' view: the clause, with the clauses
     * leading into it that state no ruling («ذهب بعض العلماء، كمالك، إلى أنه يجوز…»), reports it («ذهب بعضهم»,
     * «وقيل») and doesn't open in the fatwa's own voice («والصواب أنه لا حرج…»).
     *
     * @return list<string>
     */
    private function reportedRulingWords(string $quote, string $value): array
    {
        $clauses = array_values(array_filter(array_map('trim', preg_split('/[.!?؟؛;:\n،,]+/u', $quote) ?: []), fn (string $clause): bool => $clause !== ''));
        $words = [];

        foreach ($clauses as $k => $clause) {
            if (self::opensOwnVoice($clause) || $this->polarity->stated($value, [], $clause) === 0) {
                continue;
            }

            $lead = [];

            for ($j = $k - 1; $j >= max(0, $k - 2) && ! self::opensOwnVoice($clauses[$j]) && $this->polarity->rulings($clauses[$j]) === []; $j--) {
                array_unshift($lead, $clauses[$j]);
            }

            if (self::attributesToOthers(implode(' ', [...$lead, $clause]))) {
                array_push($words, ...Lexicon::contentStems($clause));
            }
        }

        return $words;
    }

    /**
     * What a support quote says in the fatwa's own voice: all of it unless it is a reported view; of a sentence that
     * reports others and then turns to its own («فذهب بعض العلماء إلى… وقال آخرون: تجب، وهذا هو الصواب، أي وجوب…»),
     * the part from that turn on; else nothing.
     *
     * @param  array{fatwaId: string, quote: string}  $quote
     */
    private function ownVoice(array $quote): string
    {
        if (! $this->isReported($quote)) {
            return $quote['quote'];
        }

        // A sentence that opens in its own voice and only then reports others («فإن الوتر سنة مؤكدة، ويرى بعض العلماء
        // أنه واجب، لكن الجمهور…»): its opening clauses are its own, unless the report points back at them («وهو قول…»).
        $lead = '';

        if (self::attributesToOthers($quote['quote']) && $this->contraryTurn($quote) === null) {
            $clauses = preg_split('/(?<=[،,؛;])/u', $quote['quote']) ?: [];
            $opening = [];

            foreach ($clauses as $clause) {
                if (self::attributesToOthers($clause) || preg_match(self::VIEW_LIST_PATTERN, Arabic::normalize($clause)) === 1) {
                    $pointsBack = preg_match('/^\s*[وف]?(هو|هذا|وهذا|به|اليه|بهذا)(\s|$)|^\s*[وف]?(ذهب|قال)\s+(اليه|به)/u', Arabic::normalize($clause)) === 1;
                    $lead = $pointsBack || count($opening) === count($clauses) ? '' : implode(' ', $opening);
                    break;
                }

                $opening[] = $clause;
            }
        }

        $words = preg_split('/\s+/u', $quote['quote']) ?: [];

        foreach ($words as $i => $word) {
            $token = (string) preg_replace('/^(و|ف)(?=..)/u', '', Lexicon::tokens($word)[0] ?? '');

            // «لكن الجمهور على…»: a turn to the majority view the fatwa adopts.
            $majorityTurn = in_array($token, ['لكن', 'لكنه'], true) && preg_match('/^(ال)?(جمهور|جماهير)/u', Lexicon::tokens($words[$i + 1] ?? '')[0] ?? '') === 1;

            if (in_array($token, self::OWN_VOICE_TURNS, true) && ($token !== 'لكن' || $majorityTurn)) {
                $rest = implode(' ', array_slice($words, $i));
                $normalized = Arabic::normalize($rest);

                // «والراجح ما ذهب إليه الجمهور»: the fatwa adopting the majority's view speaks for itself.
                return trim($lead.' '.(! self::attributesToOthers($rest) || $majorityTurn || (preg_match(self::MAJORITY_PATTERN, $normalized) === 1 && preg_match(self::MINORITY_PATTERN, $normalized) !== 1) ? $rest : ''));
            }
        }

        return $lead;
    }

    /** Words that open a condition or exception on a ruling (normalized). «إن» is left out: it reads as «أن». */
    private const CONDITION_PATTERN = '/(?:^|\s)(?:و|ف)?(اذا|الا|بشرط|شريطه|مالم|ما\s+لم|باذن|ان\s+كان|ان\s+كانت|عند\s+(?:خوف|الحاجه|الضروره|العجز|المرض|العذر))\s+((?:\S+\s*){1,7})/u';

    /** Comparatives that set the case a ruling applies to («أربعة أيام فأكثر», «أقل من النصاب»). */
    private const COMPARATIVES = ['اكثر', 'فاكثر', 'اقل', 'فاقل', 'فوق', 'فما', 'دون', 'ازيد', 'زياده'];

    /**
     * Whether the text keeps, for each ruling it states, the conditions its best-matching support sentence attaches:
     * most of each condition's words are in the text, a negation in a condition is kept as it is, and no comparative
     * («أقل», «فأكثر») is added or dropped.
     *
     * @param  list<array{fatwaId: string, quote: string}>  $support
     */
    private function keepsConditions(string $text, array $support): bool
    {
        $claim = Arabic::normalize($text);
        $claimStems = array_flip(Lexicon::contentStems($text));
        $sources = array_map(fn (array $quote): string => Arabic::normalize($quote['quote']), $support);
        // «بدون» is «دون» ("without"): «دون علمها» from «بدون علمها» adds no comparative.
        $comparatives = fn (string $normalized): array => array_values(array_intersect(array_map(fn (string $word): string => $word === 'بدون' ? 'دون' : $word, explode(' ', $normalized)), self::COMPARATIVES));
        // «ما» counts too: «حتى ما يعقل» is «حتى لا يعقل».
        $negated = fn (string $phrase): bool => preg_match('/(^|\s)(لا|لم|ولم|فلم|ليس|غير|ما|لن)\s/u', ' '.$phrase.' ') === 1;
        $phrases = function (string $normalized): array {
            preg_match_all(self::CONDITION_PATTERN, $normalized, $found, PREG_SET_ORDER);
            // «وإن كان الراجح…» concedes, it sets no condition.
            $found = array_values(array_filter($found, fn (array $condition): bool => ! (in_array($condition[1], ['ان كان', 'ان كانت'], true)
                && in_array((string) preg_replace('/^(و|ف)(?=..)/u', '', explode(' ', trim($condition[2]))[0]), self::PREFERENCE_WORDS, true))));

            return array_map(fn (array $condition): string => (string) preg_split('/\s(و|ف)?(لان|لانه|لانها|كما|حيث|وهذا|وذلك)\s/u', ' '.$condition[2])[0], $found);
        };

        // No comparative the support doesn't have («أقل من أربعة أيام» from «أربعة أيام فأكثر»).
        if (array_diff($comparatives($claim), $comparatives(implode(' ', $sources))) !== []) {
            return false;
        }

        // A condition keeps its negation: «إذا كان الإمام لا يقرأ» from «إذا كان الإمام يقرأ».
        $sourceClauses = array_merge(...array_map(fn (array $quote): array => array_map(Arabic::normalize(...), preg_split('/[.!?؟؛;:\n،,]+/u', $quote['quote']) ?: []), $support) ?: [[]]);
        $sourcePhrases = array_merge(...array_map($phrases, $sourceClauses) ?: [[]]);

        // Clause by clause, like the support («…، ما دام…» is not part of the condition before it).
        foreach (array_merge(...array_map(fn (string $clause): array => $phrases(Arabic::normalize($clause)), preg_split('/[.!?؟؛;:\n،,]+/u', $text) ?: []) ?: [[]]) as $own) {
            $ownStems = array_flip(Lexicon::contentStems($own));
            $match = null;
            $shared = 1;

            foreach ($sourcePhrases as $phrase) {
                $count = count(array_intersect_key(array_flip(Lexicon::contentStems($phrase)), $ownStems));

                if ($count > $shared) {
                    [$shared, $match] = [$count, $phrase];
                }
            }

            if ($match !== null && $negated($own) !== $negated($match)) {
                return false;
            }
        }

        // Each ruling keeps most words of every condition its best-matching sentence attaches to it.
        foreach ($this->polarity->rulingScopes($text) as ['value' => $value, 'scope' => $scope]) {
            // The clause that states it best, and the clause after it when that one only adds a condition
            // («يجوز…، إذا خافت النسيان»); not a later clause with a ruling of its own («…، ولا يجوز… إذا…»).
            $carrier = null;
            $best = 0;

            foreach ($support as $quote) {
                $clauses = array_map(Arabic::normalize(...), preg_split('/[.!?؟؛;:\n،,]+/u', $quote['quote']) ?: []);

                foreach ($clauses as $k => $clause) {
                    $stated = $this->polarity->stated($value, $scope, $clause);

                    if ($stated > $best) {
                        $next = $clauses[$k + 1] ?? '';
                        $addsCondition = preg_match(self::CONDITION_PATTERN, ' '.trim($next)) === 1 && preg_match('/^\s*(و|ف)?(اذا|الا|بشرط|شريطه|مالم|باذن|ان كان|ان كانت|عند)\s/u', $next) === 1 && $this->polarity->rulings($next) === [];
                        [$best, $carrier] = [$stated, $addsCondition ? "{$clause} {$next}" : $clause];
                    }
                }
            }

            foreach ($carrier === null ? [] : $phrases($carrier) as $phrase) {
                $stems = array_values(array_unique(Lexicon::contentStems($phrase)));

                if ($stems !== [] && count(array_intersect_key(array_flip($stems), $claimStems)) < (int) ceil(count($stems) * 0.5)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Words of time and frequency, which change a ruling when changed («كل شهر» for «كل عام»). */
    private const TIME_WORDS = ['يوم', 'ايام', 'اسبوع', 'اسابيع', 'شهر', 'اشهر', 'شهور', 'سنوات', 'سنين', 'عام', 'اعوام', 'حول', 'ساعه', 'ساعات', 'ليله', 'ليال', 'مره', 'مرتين', 'مرات', 'دقيقه', 'دقايق'];

    /**
     * Whether a clause of the text that states a ruling has a word of time or frequency its support doesn't have
     * («سنة» is left out: it is also the Sunnah).
     *
     * @param  list<string>  $quotes
     */
    private function changesTime(string $text, array $quotes): bool
    {
        $time = array_flip(array_map(Lexicon::stem(...), self::TIME_WORDS));
        $supported = array_flip(Lexicon::contentStems(implode(' ', $quotes)));

        foreach (preg_split('/[.!?؟؛;:\n،,]+/u', $text) ?: [] as $clause) {
            if ($this->polarity->rulings($clause) === []) {
                continue;
            }

            foreach (Lexicon::contentStems($clause) as $stem) {
                if (isset($time[$stem]) && ! isset($supported[$stem])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The fatwas a name may be attached to for this claim: one of their cited sentences, in their own voice, states
     * each of its rulings with the same value on the same subject (at least half of the ruling's words), and the
     * fatwa says nothing different about it elsewhere. A claim with no ruling needs half its words in one fatwa's own
     * cited sentences. Filler sentences, or sentences with a different ruling or subject, never count.
     *
     * @param  list<array{fatwaId: string, quote: string}>  $support
     * @param  array<string, string>  $sources  normalized texts
     * @param  list<string>  $allNames
     * @return list<string>
     */
    private function carriers(array $support, string $claim, array $sources, array $allNames): array
    {
        // Names attribute; they aren't the ruling's subject.
        $nameStems = array_flip(Lexicon::contentStems(implode(' ', $allNames)));
        $scopes = array_map(fn (array $scope): array => [...$scope, 'scope' => array_values(array_filter($scope['scope'], fn (string $stem): bool => ! isset($nameStems[$stem])))], $this->polarity->rulingScopes($claim));
        $carriers = [];

        foreach (array_unique(array_column($support, 'fatwaId')) as $fatwaId) {
            $own = array_values(array_filter(array_map(
                fn (array $quote): string => $this->ownVoice($quote),
                array_filter($support, fn (array $quote): bool => $quote['fatwaId'] === $fatwaId),
            )));

            if ($own === []) {
                continue;
            }

            foreach ($scopes as ['value' => $value, 'scope' => $scope]) {
                $needed = $scope === [] ? 1 : min(count($scope), max(2, (int) ceil(count($scope) / 2)));
                $stated = max(array_map(fn (string $quote): int => $this->polarity->stated($value, $scope, $quote), $own));

                if ($stated < $needed) {
                    continue 2;
                }
            }

            if ($scopes === [] && $this->coverage($claim, $own, [], $sources, $allNames)['support'] < 0.5) {
                continue;
            }

            if ($this->polarity->contradictedBy($claim, self::withoutQuestion($this->rawSources[$fatwaId] ?? ''))) {
                continue;
            }

            $carriers[] = $fatwaId;
        }

        return $carriers;
    }

    /**
     * Whether the text names someone, and only sources or muftis among the given ones.
     *
     * @param  list<string>  $allowed
     * @param  list<string>  $allNames
     */
    private function namesOnly(string $text, array $allowed, array $allNames): bool
    {
        if (! $this->mentions($text, $allowed)) {
            return false;
        }

        $key = fn (string $name): string => implode(' ', array_map(Lexicon::stem(...), Lexicon::tokens($name)));
        $allowedSet = array_flip(array_map($key, $allowed));

        foreach ($allNames as $name) {
            if (! isset($allowedSet[$key($name)]) && $this->mentions($text, [$name])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the fatwa gives the quoted view as others' rather than its own. The whole sentence is read, across
     * commas («ذكر بعض الفقهاء أقوالًا، منها: يجوز…»): the quote itself, what leads up to it since the fatwa last
     * turned to its own voice («…، والصحيح»), and what follows it until the next such turn («يجوز… عند جمع من أهل
     * العلم، والراجح المنع»). A quote that opens in the fatwa's own voice («والصحيح أنه…») starts fresh.
     */
    public static function reportedView(string $rawSource, string $quote): bool
    {
        $needle = Arabic::normalize($quote);

        if (self::attributesToOthers($quote)) {
            return true;
        }

        $sentence = Arabic::normalize(self::sentencesAround($rawSource, $quote));
        $position = $needle === '' ? false : mb_strpos($sentence, $needle);

        if ($position === false) {
            return self::attributesToOthers(self::clauseAround($rawSource, $quote));
        }

        $turn = fn (string $token): bool => in_array((string) preg_replace('/^(و|ف)(?=..)/u', '', $token), self::OWN_VOICE_TURNS, true);
        $before = Lexicon::tokens(mb_substr($sentence, 0, $position));
        $after = Lexicon::tokens(mb_substr($sentence, $position + mb_strlen($needle)));

        $lead = [];

        if (! $turn(explode(' ', $needle)[0])) {
            for ($i = count($before) - 1; $i >= 0 && ! $turn($before[$i]); $i--) {
                array_unshift($lead, $before[$i]);
            }
        }

        $trail = [];

        foreach ($after as $token) {
            if ($turn($token)) {
                break;
            }

            $trail[] = $token;
        }

        return self::attributesToOthers(implode(' ', [...$lead, $needle, ...$trail]));
    }

    /**
     * Whether the text reports a view as held by others («ذهب بعضهم», «قال بعض العلماء», «وقيل», "some held").
     */
    public static function attributesToOthers(string $text): bool
    {
        $normalized = ' '.mb_strtolower(Arabic::normalize($text)).' ';

        foreach (self::OTHERS_VIEW_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the entailment judge reads for a quote: the verbatim quote, widened only within its own clause or sentence
     * (split at «،», commas and sentence ends) to at most JUDGE_CONTEXT_WORDS words, with "…" where the clause goes on.
     * A quote that can't be located is returned as it is.
     */
    public static function clauseAround(string $rawSource, string $quote, int $maxWords = self::JUDGE_CONTEXT_WORDS): string
    {
        $clauses = preg_split('/(?<=[.!?؟؛،,;\n])/u', self::withoutQuestion($rawSource)) ?: [];
        $words = [];
        $clauseOf = [];
        $tokens = [];
        $wordOf = [];

        foreach ($clauses as $c => $clause) {
            foreach (preg_split('/\s+/u', trim($clause)) ?: [] as $word) {
                if ($word === '') {
                    continue;
                }

                $words[] = $word;
                $clauseOf[] = $c;

                foreach (array_filter(explode(' ', Arabic::normalize($word))) as $token) {
                    $tokens[] = $token;
                    $wordOf[] = count($words) - 1;
                }
            }
        }

        $needle = array_values(array_filter(explode(' ', Arabic::normalize($quote))));
        $length = count($needle);

        for ($i = 0, $start = null; $length > 0 && $i + $length <= count($tokens); $i++) {
            if (array_slice($tokens, $i, $length) === $needle) {
                $start = $i;
                break;
            }
        }

        if ($start === null) {
            return $quote;
        }

        $first = $wordOf[$start];
        $last = $wordOf[$start + $length - 1];
        $clauseStart = array_search($clauseOf[$first], $clauseOf, true);
        $clauseEnd = count($clauseOf) - 1 - (int) array_search($clauseOf[$last], array_reverse($clauseOf), true);
        $room = max(0, $maxWords - ($last - $first + 1));
        $before = min($first - $clauseStart, (int) ceil($room / 2));
        $after = min($clauseEnd - $last, $room - $before);
        $before = min($first - $clauseStart, $room - $after);
        $from = $first - $before;
        $to = $last + $after;

        return ($from > $clauseStart ? '… ' : '').implode(' ', array_slice($words, $from, $to - $from + 1)).($to < $clauseEnd ? ' …' : '');
    }

    /**
     * The whole sentence(s) of the fatwa's answer that a verbatim quote was taken from (the quote itself if not found).
     */
    public static function sentencesAround(string $rawSource, string $quote): string
    {
        $raw = self::withoutQuestion($rawSource);
        $sentences = array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?؟؛\n])/u', $raw) ?: [])));
        $normalized = array_map(fn (string $sentence): string => Arabic::normalize($sentence), $sentences);
        $needle = Arabic::normalize($quote);
        $joined = '';
        $starts = [];

        foreach ($normalized as $k => $sentence) {
            $starts[$k] = mb_strlen($joined);
            $joined .= $sentence.' ';
        }

        $position = $needle === '' ? false : mb_strpos($joined, $needle);

        if ($position === false) {
            return $quote;
        }

        $end = $position + mb_strlen($needle);
        $covering = array_filter(array_keys($starts), fn (int $k): bool => $starts[$k] < $end && $position <= $starts[$k] + mb_strlen($normalized[$k]));

        return implode(' ', array_map(fn (int $k): string => $sentences[$k], $covering));
    }

    /**
     * Replaces titles that failed the check with neutral labels for their place in the summary.
     *
     * @param  array<string, mixed>|null  $agreed
     * @param  list<array<string, mixed>>  $views
     * @return array{0: array<string, mixed>|null, 1: list<array<string, mixed>>}
     */
    private function relabel(?array $agreed, array $views): array
    {
        return [
            $agreed ? self::labelled($agreed, null) : null,
            array_map(fn (array $view, int $i): array => self::labelled($view, $i), $views, array_keys($views)),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, string>  $sources  normalized texts
     * @return list<array{fatwaId: string, quote: string}>
     */
    private function validSupport(array $item, array $sources): array
    {
        $support = [];
        $words = 0;

        foreach ($this->listOf($item['support'] ?? []) as $quote) {
            $fatwaId = is_array($quote) ? (string) ($quote['fatwaId'] ?? '') : '';
            $text = is_array($quote) ? (string) preg_replace('/\[(F\d+\.)?E\d+[^\]]*\]/u', ' ', (string) ($quote['quote'] ?? '')) : '';
            $normalized = Arabic::normalize($text);
            $meaningful = Lexicon::withoutBoilerplate($text);

            if (! isset($sources[$fatwaId]) || $meaningful === '' || ! str_contains($sources[$fatwaId], $normalized)) {
                continue;
            }

            $entry = ['fatwaId' => $fatwaId, 'quote' => trim($text)];

            // Where the sentence sits in its fatwa (SourceSentences::sentence()), for the reported-view guard and the judge.
            foreach (['id', 'before', 'after', 'passage'] as $key) {
                if (isset($quote[$key])) {
                    $entry[$key] = $quote[$key];
                }
            }

            $support[] = $entry;
            $words += count(explode(' ', $meaningful));
        }

        // The support as a whole must say something: MIN_QUOTE_WORDS words once opening and closing formulas are removed.
        return $words >= self::MIN_QUOTE_WORDS ? $support : [];
    }

    /**
     * Each field checked on its own, so a number at the end of one field doesn't take the next field's first word as
     * its qualifier.
     *
     * @param  list<string>  $parts
     * @param  list<string>  $quotes
     */
    private function allNumbersSupported(array $parts, array $quotes, bool $qualifiers): bool
    {
        foreach ($parts as $part) {
            if (! $this->numbersSupported($part, $quotes, $qualifiers)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every number in the prose appears in a support quote; with `qualifiers`, the first word after it (its unit or
     * measure) also follows that number in the quote.
     *
     * @param  list<string>  $quotes
     */
    private function numbersSupported(string $prose, array $quotes, bool $qualifiers): bool
    {
        $supportOccurrences = $this->numbers->occurrences(implode(' . ', $quotes));

        foreach ($this->numbers->occurrences($prose) as $occurrence) {
            $matches = array_filter($supportOccurrences, fn (array $candidate): bool => array_intersect($occurrence['values'], $candidate['values']) !== []);

            if ($matches === []) {
                return false;
            }

            if (! $qualifiers) {
                continue;
            }

            $qualifier = $this->qualifier($occurrence['next']);

            if ($qualifier === null) {
                continue;
            }

            $nearby = [];

            foreach ($matches as $match) {
                foreach ($match['next'] as $k => $token) {
                    $nearby[Lexicon::stem($token)] = true;
                    // The same unit spelled apart or with tanween («كيلو متراً» for «كيلومترًا»), or another form of
                    // its word («أصحاب» for «الصحابة»): compared by rough root.
                    $nearby['root:'.self::qualifierRoot($token)] = true;

                    if (isset($match['next'][$k + 1])) {
                        $nearby['root:'.self::qualifierRoot($token.$match['next'][$k + 1])] = true;
                    }
                }
            }

            if (! isset($nearby[$qualifier]) && ! isset($nearby['root:'.self::qualifierRoot($qualifier)])) {
                return false;
            }
        }

        return true;
    }

    /**
     * A rough root of a unit word: its stem without a tanween alif, the verb prefix and weak letters, first three letters.
     */
    private static function qualifierRoot(string $token): string
    {
        $stem = Lexicon::stem($token);
        $stem = mb_strlen($stem) >= 5 && str_ends_with($stem, 'ا') ? mb_substr($stem, 0, -1) : $stem;
        $stem = mb_strlen($stem) > 3 ? (string) preg_replace('/^[يتنا]/u', '', $stem) : $stem;

        return mb_substr((string) preg_replace('/[اويءه]/u', '', $stem), 0, 3);
    }

    /**
     * The stem of the first content word after a number, if any.
     *
     * @param  list<string>  $next
     */
    private function qualifier(array $next): ?string
    {
        foreach ($next as $token) {
            if (Lexicon::isStopword($token) || $this->numbers->values($token) !== []) {
                continue;
            }

            return Lexicon::stem($token);
        }

        return null;
    }

    /**
     * True when the text prefers a view in the site's own voice. «عندنا», «نرى», «نرجح»… always do, unless they sit
     * inside «quotation marks» in a sentence that names its source («ويقول مركز الفتوى: «وهو ما نفتي به»»).
     * «الراجح», «الصحيح», «الأظهر»… do unless attributed to a source: its name in the few words before the word,
     * «عند <name>» right after it, or an attribution verb with the name earlier in the sentence («ويرى ابن باز أن…
     * وأن هذا هو الراجح»). A name elsewhere in the sentence doesn't count.
     *
     * @param  list<string>  $names
     */
    private function speaksForItself(string $text, array $names): bool
    {
        foreach (preg_split('/[.!?؟؛;\n]+/u', $text) ?: [] as $sentence) {
            if ($this->mentions($sentence, $names)) {
                $sentence = (string) preg_replace('/«[^»]*»|"[^"]*"/u', ' ', $sentence);
            }

            $tokens = Lexicon::tokens($sentence);

            foreach ($tokens as $i => $token) {
                $bare = (string) preg_replace('/^(و|ف)(?=..)/u', '', $token);

                if (in_array($token, self::FIRST_PERSON, true) || in_array($bare, self::FIRST_PERSON, true)) {
                    return true;
                }

                // «يظهر لي», «يبدو لي», «يترجح لي»: the writer's own view.
                if (in_array($bare, ['يظهر', 'يبدو', 'يترجح', 'يتبين', 'يتضح', 'ظهر', 'بدا', 'تبين'], true) && ($tokens[$i + 1] ?? '') === 'لي') {
                    return true;
                }

                if (! in_array($token, self::PREFERENCE_WORDS, true) && ! in_array($bare, self::PREFERENCE_WORDS, true)) {
                    continue;
                }

                // «… والأظهر أنه …» starts a statement of its own: an attribution verb earlier doesn't cover it.
                if (! $this->attributed($tokens, $i, $names, verbAllowed: $bare === $token)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $names
     */
    private function attributed(array $tokens, int $i, array $names, bool $verbAllowed = true): bool
    {
        $before = implode(' ', array_slice($tokens, max(0, $i - self::ATTRIBUTION_WINDOW), min($i, self::ATTRIBUTION_WINDOW)));

        if ($this->mentions($before, $names)) {
            return true;
        }

        $after = array_slice($tokens, $i + 1, 4);

        if (in_array($after[0] ?? '', ['عند', 'لدي'], true) && $this->mentions(implode(' ', $after), $names)) {
            return true;
        }

        // «الراجح والمفتى به عند إسلام ويب»: «عند <name>» a few joined words on.
        foreach (array_slice($tokens, $i + 1, 3) as $k => $token) {
            if (in_array($token, ['عند', 'لدي'], true)) {
                if ($this->mentions(implode(' ', array_slice($tokens, $i + 2 + $k, 4)), $names)) {
                    return true;
                }

                break;
            }

            if (! preg_match('/^(و|ف)\S+$|^(به|بها|فيه|عليه)$/u', $token) || in_array($token, ['وان', 'وانه', 'فان', 'فانه'], true)) {
                break;
            }
        }

        // «ذكر إسلام ويب أن… فالصواب عنده…»: «عنده» refers to the source named with the verb before it.
        if (in_array($after[0] ?? '', ['عنده', 'عندها', 'عندهم', 'لديه', 'لديها'], true)) {
            for ($j = 0; $j < $i; $j++) {
                if (in_array((string) preg_replace('/^(و|ف)(?=..)/u', '', $tokens[$j]), self::ATTRIBUTION_VERBS, true) && $this->mentions(implode(' ', array_slice($tokens, $j + 1, 5)), $names)) {
                    return true;
                }
            }
        }

        for ($j = 0; $verbAllowed && $j < $i; $j++) {
            $verb = (string) preg_replace('/^(و|ف)(?=..)/u', '', $tokens[$j]);

            if (in_array($verb, self::ATTRIBUTION_VERBS, true) && $this->mentions(implode(' ', array_slice($tokens, $j + 1, 5)), $names)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the text names any of the given sources or muftis (matched on word stems, so «للفوزان» names «الفوزان»).
     *
     * @param  list<string>  $names
     */
    public function mentions(string $text, array $names): bool
    {
        $haystack = ' '.implode(' ', array_map(Lexicon::stem(...), Lexicon::tokens($text))).' ';

        foreach ($names as $name) {
            $needle = implode(' ', array_map(Lexicon::stem(...), Lexicon::tokens($name)));

            if ($needle !== '' && str_contains($haystack, " {$needle} ")) {
                return true;
            }
        }

        return false;
    }

    /**
     * A "differ" headline is a neutral template, so it can never take a side. An "agree" headline is the model's when
     * it passes the same checks as a point (and later the judge), else the agreed point's title.
     *
     * @param  array<string, mixed>  $draft
     * @param  list<array<string, mixed>>  $survivors
     * @param  array<string, mixed>|null  $agreed
     * @param  array<string, string>  $sources
     * @param  array<string, list<string>>  $names
     */
    private function headline(array $draft, array $survivors, string $kind, ?array $agreed, array $sources, array $names, int $viewCount): string
    {
        if ($kind === 'differ') {
            return self::differHeadline($viewCount);
        }

        $support = array_merge(...array_map(fn (array $item): array => $item['support'], $survivors) ?: [[]]);
        $quotes = array_column($support, 'quote');
        $cited = array_values(array_unique(array_merge(...array_map(fn (array $item): array => $item['citations'], $survivors) ?: [[]])));
        $allNames = array_merge(...array_values($names) ?: [[]]);
        $headline = trim((string) ($draft['headline'] ?? ''));

        // The same checks as a point's body: a headline is read first, so it can't drop a condition, change a time
        // or state a reported view as the answer either.
        $grounded = $headline !== ''
            && $this->grounded($this->coverage($headline, $quotes, $cited, $sources, $allNames))
            && $this->numbersSupported($headline, $quotes, qualifiers: true)
            && $this->polarity->consistent($headline, $quotes)
            && $this->polarity->anchored($headline, $quotes)
            && ! $this->speaksForItself($headline, $allNames)
            && $this->keepsConditions($headline, $support)
            && ! $this->changesTime($headline, $quotes)
            && (self::attributesToOthers($headline) || $this->carriedByOwnVoice($headline, $support));

        return $grounded ? $headline : (string) ($agreed['title'] ?? self::differHeadline(0));
    }

    /**
     * «اختلفت المصادر في هذه المسألة على قولين».
     */
    public static function differHeadline(int $views): string
    {
        return match (true) {
            $views === 2 => 'اختلفت المصادر في هذه المسألة على قولين',
            $views === 3 => 'اختلفت المصادر في هذه المسألة على ثلاثة أقوال',
            $views === 4 => 'اختلفت المصادر في هذه المسألة على أربعة أقوال',
            default => 'اختلفت المصادر في هذه المسألة',
        };
    }

    /**
     * The fatwa's text without the questioner's words: a support quote must come from the answer (or the source's own
     * summary), never from the question it answers. Texts built by SummaryWriter hold no question already.
     */
    public static function withoutQuestion(string $text): string
    {
        return (string) preg_replace('/^\s*(السؤال|س)\s*[:：].*?(الجواب|ج)\s*[:：]/su', '', $text);
    }

    /**
     * @param  array<string, string>  $sources
     * @return array<string, true>
     */
    private function stemsOf(string $id, array $sources): array
    {
        return $this->sourceStems[$id] ??= array_fill_keys(Lexicon::contentStems($sources[$id] ?? ''), true);
    }

    /**
     * Grounded when most content words appear in the support quotes or cited fatwas (`all`) and a good share appear in
     * the support quotes themselves (`support`). Long fatwas contain most of a topic's vocabulary, so `all` alone lets
     * an invented ruling on the same topic through; `support` alone is too strict for honest paraphrase.
     *
     * @param  array{all: float, support: float}  $coverage
     */
    private function grounded(array $coverage): bool
    {
        [$minAll, $minSupport] = $this->thresholds();

        return $coverage['all'] >= $minAll && $coverage['support'] >= $minSupport;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function thresholds(): array
    {
        $config = app()->bound('config');

        return [
            $this->minCoverage ?? (float) ($config ? config('fatwarag.ask.grounding_min_coverage', 0.7) : 0.7),
            $this->minSupportCoverage ?? (float) ($config ? config('fatwarag.ask.grounding_min_support_coverage', 0.4) : 0.4),
        ];
    }

    /**
     * Why items were dropped in the last check, for the regeneration request (never shown to readers).
     *
     * @return list<array{item: string, reason: string}>
     */
    public function rejections(): array
    {
        return $this->rejections;
    }

    /**
     * What the heuristic ruling checks flagged on items left to the claims judge (see `$judged`).
     *
     * @return list<array{item: string, reason: string}>
     */
    public function deferred(): array
    {
        return $this->deferred;
    }

    /**
     * @return list<mixed>
     */
    private function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param  list<array<string, mixed>|null>  $items
     * @return list<array<string, mixed>>
     */
    private function keep(array $items): array
    {
        return array_values(array_filter($items));
    }
}
