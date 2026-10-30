<?php

namespace App\Answers;

use App\Models\Fatwa;
use App\Models\FatwaLink;
use App\Models\HadithQuote;
use App\Services\AiSpend;
use App\Services\Alerts;
use App\Support\Arabic;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Throwable;

/**
 * Writes the grounded summary (الخلاصة) of the top retrieved fatwas and passes it through the safeguard.
 *
 * The model reads every listed fatwa in full, never trimmed, plus, one level deep, the fatwas they refer to («وانظر
 * الفتوى رقم…», at most two per fatwa, also in full), with every sentence of their answers numbered («[F2.S14] …»,
 * SourceSentences). Only when that would pass the model's context are whole cross-references left out (then, last,
 * whole lower-ranked fatwas), never part of a fatwa. It writes one JSON line per item and cites
 * sentence ids, never quotes: each id is resolved to the stored sentence, which is the support every check reads.
 * It never types a verse or hadith either: it cites a marker («F2.E3») and the evidence is filled from storage.
 *
 * The output is streamed. Each item is checked (GroundingCheck) as soon as its line is complete and goes to the
 * entailment judge right away, several at once (SummaryTasks); English items are translated and judged again. An
 * item that passes everything is reported through `$emit` in display order, and nothing is reported before the
 * summary can no longer fail its checks (a disagreement only once two views have passed). Only a later error (the
 * judge, the translation or the model breaking) can still end it as `failed`; the client then discards what it got.
 * Anything the safeguard rejects is removed: a body that fails is first pruned of the sentences that fail (by the
 * lexical checks, or as the judge names them, then judged once more), and only what passes is shown. «العهدة على
 * المصدر»: a disagreement left with one side shows it attributed, with a line pointing to the fatwas for the other views
 * (`note`), and an agreement whose agreed point failed shows its preferences. Only if nothing usable
 * survives is the result `failed`, and when the monthly budget is spent `paused`. The page still shows the fatwa list.
 *
 * A question asking several things («أولًا… ثانيًا…») is answered part by part (`parts`): each part has its own
 * agreement or disagreement, checked exactly as a one-part summary is, and a part that fails, or that no fatwa
 * answers, is shown as unanswered while another part stands. The common case, one part, is written as before.
 *
 * The summary is always written and checked in Arabic; English readers get SummaryTranslator's faithful translation
 * of the checked items.
 */
class SummaryWriter implements Agent, HasProviderOptions
{
    use Promptable;

    public const PURPOSE = 'summary';

    public const MAX_CROSS_REFS_PER_FATWA = 2;

    /** The most parts a question is answered in; a longer list is cut to these. */
    public const MAX_PARTS = 4;

    /** A part's label is a short phrase from the question, at most this many words. */
    private const MAX_LABEL_WORDS = 12;

    public const MAX_EVIDENCE_PER_POINT = 2;

    /** A first draft and, when nothing usable survives the checks, one rewrite. */
    public const MAX_ATTEMPTS = 2;

    /** The summary's parts in display order. */
    public const SECTIONS = ['agreed' => 0, 'view' => 1, 'preference' => 2, 'caution' => 3];

    /** Words in a source's or mufti's name that don't identify it on their own. */
    private const GENERIC_NAME_WORDS = [
        'موقع', 'الشيخ', 'شيخ', 'مجموع', 'فتاوي', 'بن', 'ابن', 'عبد', 'مركز', 'الفتوي', 'الاسلام', 'اللجنه', 'الدايمه',
        'للبحوث', 'العلميه', 'والافتاء', 'صالح', 'العزيز', 'محمد', 'الله', 'سماحه', 'فضيله',
        'sheikh', 'shaykh', 'fatwas', 'fatwa', 'of', 'the', 'ibn', 'bin', 'al', 'center', 'centre', 'islam', 'committee',
        'permanent', 'salih', 'muhammad', 'abd', 'abdul', 'aziz', 'website',
    ];

    /** How readers usually write each source's name. */
    private const SOURCE_ALIASES = [
        'islamweb' => ['اسلام ويب', 'islamweb', 'islam web'],
        'islamqa' => ['سوال وجواب', 'islamqa', 'islam qa', 'islam q a'],
        'binbaz' => ['ابن باز', 'بن باز', 'ibn baz', 'bin baz'],
        'fawzan' => ['الفوزان', 'fawzan'],
    ];

    private string $locale = 'ar';

    /** @var (Closure(string, array<string, mixed>): mixed)|null */
    private ?Closure $emit = null;

    /** @var (Closure(): bool)|null */
    private ?Closure $alive = null;

    /** The reader has gone (or the time ran out): stop, spend nothing more. */
    private bool $gone = false;

    /** When the summary started (monotonic `hrtime`): `set_time_limit` counts CPU time on Linux, so wall time is bounded here. */
    private int $startedAt = 0;

    /** The visitor (their IP), for the per-visitor cap on step processes. */
    private string $client = 'unknown';

    private SourceSentences $sentences;

    private SummaryTasks $tasks;

    /** @var array<string, string> "source — mufti" of each fatwa given to the model, by public id */
    private array $sourceLabels = [];

    /** @var array<string, string> the answer text of each fatwa given to the model, by public id */
    private array $sourceTexts = [];

    /** @var array<string, list<string>> the names each fatwa may be attributed by, by public id */
    private array $names = [];

    /** @var list<string> */
    private array $crossRefIds = [];

    /** @var array<string, array<int, true>> the evidence markers the model was shown, by fatwa public id */
    private array $shownMarkers = [];

    /** @var array<string, array{collection: string, number: int}> */
    private array $refs = [];

    /** @var array<string, string> */
    private array $glossary = [];

    /** @var list<array{label: string, fatwaIds: list<string>}> the question's parts as search found them */
    private array $searchParts = [];

    /** The reader's question, which a part's label may only rephrase. */
    private string $question = '';

    /** @var list<string> the question's parts as the draft named them; empty for one part (the common case) */
    private array $labels = [];

    /** @var array<int, string> each part's label as shown, by part: its own when it passed its check (and, for English, its checked translation), else a neutral one */
    private array $shownLabels = [];

    /** For English, whether the part labels' translations have been checked (their items wait for it). */
    private bool $labelsSettled = true;

    /** @var array<string, array{arabic: string, english: string}> for English, the labels' drafted translations the judge is checking */
    private array $labelPairs = [];

    /** Whether the draft has written its first line (a parts line counts only as the first). */
    private bool $begun = false;

    /** @var array<int, array{kind: 'agree'|'differ'|'none', headline: string}> each part's first line, by part (one part: the draft's first line) */
    private array $headers = [];

    /**
     * @var list<array{part: int, section: string, key: string, checked: array<string, mixed>|null, state: 'checking'|'passed'|'failed'|'skipped', judged: bool|null, rejudging: bool, headlineSupported: bool, headlineJudged: bool, verifying: bool, fields: array<string, string>, translations: array<string, string>|null, english: array<string, string>, emitted: bool}>
     */
    private array $items = [];

    /** @var array<int, int> the furthest section each part has reached; a line for an earlier one is out of order and ignored */
    private array $reached = [];

    /** The part the draft is writing; a line for an earlier part is out of order and ignored. */
    private int $currentPart = 0;

    private bool $ended = false;

    /** A judge or translation call failed: the summary fails closed. */
    private bool $fatal = false;

    /** @var array<int, true> the parts whose items may be sent */
    private array $gateOpen = [];

    /** @var list<int> the parts of the last draft that failed their checks (not those it said no fatwa answers) */
    private array $failedParts = [];

    /** @var array<int, array<string, mixed>> parts a first draft answered, kept through its rewrite, by part */
    private array $keptParts = [];

    /** @var list<string> the cross-references the kept parts cite */
    private array $keptExtraFatwaIds = [];

    /** How many parts the first draft had; a rewrite with another number is not used. */
    private int $keptPartCount = 0;

    /** @var list<string> the first draft's part labels as shown, which its rewrite keeps */
    private array $keptLabels = [];

    /** @var array<string, int> lines ignored, by why (logged for calibration, never their text) */
    private array $outOfOrder = [];

    /** Which draft this is, so a late step of a draft given up on is ignored. */
    private int $attemptNumber = 0;

    /** @var list<array{item: string, reason: string}> */
    private array $problems = [];

    /** @var array<string, true> */
    private array $stagesSent = [];

    /**
     * Diagnostics only (`common:diagnose`, site-authored questions): receives every drafted line, its check result and
     * each judge or translation step. Null when serving readers, so nothing is traced or logged.
     *
     * @var (Closure(string, array<string, mixed>): mixed)|null
     */
    private ?Closure $trace = null;

    public function __construct(
        private GroundingCheck $check,
        private AiSpend $spend,
        private Alerts $alerts,
        private SummaryTranslator $translator,
        private EntailmentJudge $judge,
        private Polarity $polarity,
    ) {}

    /**
     * The visitor this summary is for (their IP), so their step processes count against their own cap.
     */
    public function forClient(string $client): self
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Traces this writer's drafts for diagnosis (`common:diagnose`). Never set for a visitor's question.
     *
     * @param  (Closure(string, array<string, mixed>): mixed)|null  $trace
     */
    public function tracing(?Closure $trace): self
    {
        $this->trace = $trace;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function trace(string $event, array $data): void
    {
        if ($this->trace !== null) {
            ($this->trace)($event, $data);
        }
    }

    /**
     * The question's parts as search found them (each part searched on its own): the draft is asked to answer exactly
     * these parts, each from the fatwas found for it first.
     *
     * @param  list<array{label: string, fatwaIds: list<string>}>  $parts
     */
    public function forParts(array $parts): self
    {
        $this->searchParts = count($parts) >= 2 ? array_values($parts) : [];

        return $this;
    }

    public function instructions(): string
    {
        return (string) file_get_contents(resource_path('prompts/summary.md'));
    }

    public function timeout(): int
    {
        return 150;
    }

    /**
     * A cap against runaway output, well above what a summary of this length needs (real drafts citing sentence ids:
     * about 1,000–1,500 tokens). A line cut off by it is incomplete JSON, so it is never read.
     */
    public function maxTokens(): int
    {
        return (int) config('fatwarag.summary.max_output_tokens', 6000);
    }

    public function temperature(): float
    {
        return 0.2;
    }

    /**
     * The model's own reasoning before it writes (`fatwarag.summary.reasoning`, OpenRouter's `reasoning` option). Low by
     * default (the summary model can't turn it off): more delays the first line by seconds, and every item is checked
     * by the safeguard and the judge anyway.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $reasoning = (string) config('fatwarag.summary.reasoning', 'low');

        return match ($reasoning) {
            'off' => ['reasoning' => ['enabled' => false]],
            'low', 'medium', 'high' => ['reasoning' => ['effort' => $reasoning]],
            default => [],
        };
    }

    /**
     * @param  Collection<int, Fatwa>  $fatwas  most relevant first
     * @param  (Closure(string, array<string, mixed>): mixed)|null  $emit  receives `stage` and `item` events as they happen
     * @param  (Closure(): bool)|null  $alive  asked often while the summary runs: false when the reader has gone, which
     *                                         stops everything (pending checks are abandoned, no rewrite) as `failed`
     * @return array{status: 'ok'|'failed'|'paused', summary?: array<string, mixed>}
     */
    public function write(string $question, Collection $fatwas, string $locale, ?Closure $emit = null, ?Closure $alive = null): array
    {
        $this->alive = $alive;
        $this->gone = false;

        if ($this->spend->isExhausted()) {
            return ['status' => 'paused'];
        }

        $startedAt = hrtime(true);
        $this->startedAt = $startedAt;
        $this->emit = $emit;
        $this->locale = $locale === 'en' ? 'en' : 'ar';
        $this->question = $question;
        $this->send('stage', ['stage' => 'reading']);

        // Every listed fatwa (the search lists at most `fatwarag.search.max_results`), in full.
        $fatwas = new EloquentCollection($fatwas->values()->all());
        $fatwas->loadMissing(['source:id,key,name_ar,name_en', 'mufti:id,name_ar,name_en']);

        if ($fatwas->isEmpty()) {
            return ['status' => 'failed'];
        }

        $this->tasks = app(SummaryTasks::class)->forClient($this->client);

        try {
            // Step processes only boot when a step will run: the claims judge, or the translation for English.
            if ($this->locale === 'en' || $this->claimsJudge()) {
                $this->tasks->warm();
            }

            return $this->attempts($question, $this->blocks($question, $fatwas), $startedAt);
        } catch (Throwable $exception) {
            // Fail closed. Only the class: a message could echo the question, and questions are never logged.
            Log::warning('Summary failed', ['error' => $exception::class]);

            return ['status' => 'failed'];
        } finally {
            $this->tasks->stop();
        }
    }

    /**
     * The fatwa blocks the model reads, and everything the checks need about those fatwas: every listed fatwa in full,
     * then the fatwas they refer to, in full. Only when the request would pass the model's context
     * (`fatwarag.summary.max_input_tokens`) are whole cross-references left out, last first, and then whole fatwas from
     * the lowest-ranked up (never the first); a fatwa is never cut.
     *
     * @param  EloquentCollection<int, Fatwa>  $fatwas
     * @return list<string>
     */
    private function blocks(string $question, EloquentCollection $fatwas): array
    {
        $this->sentences = new SourceSentences;
        $this->sourceTexts = [];
        $this->names = [];
        $this->sourceLabels = [];
        $this->shownMarkers = [];
        $this->crossRefIds = [];
        $blocks = [];

        // About two characters a token for Arabic, which over-counts rather than under-counts.
        $room = (int) config('fatwarag.summary.max_input_tokens', 150000) * 2 - mb_strlen($this->instructions()) - mb_strlen($question) - 2000;
        $kept = $fatwas->values()->all();
        $crossRefs = $this->crossReferences($fatwas);
        $size = fn (array $kept, array $crossRefs): int => array_sum(array_map($this->blockSize(...), [...$kept, ...array_column($crossRefs, 0)]));
        $crossRefsDropped = 0;

        // Over the context: whole cross-references go first, last first; only then whole listed fatwas, the
        // lowest-ranked first, never the first one. A fatwa is never cut.
        while ($crossRefs !== [] && $size($kept, $crossRefs) > $room) {
            array_pop($crossRefs);
            $crossRefsDropped++;
        }

        while (count($kept) > 1 && $size($kept, $crossRefs) > $room) {
            array_pop($kept);
        }

        $fatwasDropped = $fatwas->count() - count($kept);
        $kept = new EloquentCollection($kept);

        foreach ($kept as $fatwa) {
            $blocks[] = $this->fatwaBlock($fatwa);
            $this->remember($fatwa);
        }

        foreach ($crossRefs as [$crossRef, $citedBy]) {
            $blocks[] = $this->fatwaBlock($crossRef, $citedBy);
            $this->remember($crossRef);
            $this->crossRefIds[] = $crossRef->public_id;
        }

        $crossRefs = array_column($crossRefs, 0);

        if ($fatwasDropped + $crossRefsDropped > 0) {
            // Never the question: only what was left out.
            Log::warning('Summary input over the model context: whole fatwas left out', [
                'cross_refs_dropped' => $crossRefsDropped,
                'fatwas_dropped' => $fatwasDropped,
                'max_input_tokens' => (int) config('fatwarag.summary.max_input_tokens', 150000),
            ]);
        }

        $this->refs = $this->hadithRefs();
        $this->glossary = $this->locale === 'en' ? $this->glossary([...$kept->all(), ...$crossRefs]) : [];
        $this->trace('sources', ['texts' => $this->sourceTexts, 'names' => $this->names, 'labels' => $this->sentences->labels(), 'crossRefs' => $this->crossRefIds]);

        return $blocks;
    }

    /**
     * About how many characters a fatwa's block takes: its title, question, source summary and answer, with room for
     * the sentence numbers and markers.
     */
    private function blockSize(Fatwa $fatwa): int
    {
        $text = mb_strlen((string) $fatwa->title) + mb_strlen($this->questionText($fatwa->question)) + mb_strlen($this->fullText($fatwa));

        return (int) ceil($text * 1.15) + 300;
    }

    private function remember(Fatwa $fatwa): void
    {
        $this->sourceTexts[$fatwa->public_id] = $this->fullText($fatwa);
        $this->names[$fatwa->public_id] = $this->namesOf($fatwa);
        $this->sourceLabels[$fatwa->public_id] = $this->sourceLabel($fatwa);
    }

    /**
     * One draft, and if nothing usable survives the checks, one rewrite told what failed, while time allows. In parts,
     * a draft whose part failed its checks is also rewritten while time allows: the parts it answered are kept as they
     * are (and not sent again), and the rewrite can only add the parts that failed.
     *
     * @param  list<string>  $blocks
     * @return array{status: 'ok'|'failed'|'paused', summary?: array<string, mixed>}
     */
    private function attempts(string $question, array $blocks, int $startedAt): array
    {
        $problems = [];
        $this->keptParts = [];
        $this->keptLabels = [];
        $this->keptExtraFatwaIds = [];
        $settled = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            if ($this->gone) {
                return ['status' => 'failed'];
            }

            if ($settled !== null && ($this->spend->isExhausted() || ! $this->hasTimeForRewrite($startedAt))) {
                return ['status' => 'ok', 'summary' => $settled];
            }

            if ($attempt > 1 && $this->spend->isExhausted()) {
                return ['status' => 'paused']; // the first draft spent the last of the budget
            }

            if ($attempt > 1 && ! $this->hasTimeForRewrite($startedAt)) {
                return ['status' => 'failed'];
            }

            $this->trace('attempt', ['attempt' => $attempt, 'problems' => $problems]);
            $summary = $this->attempt($this->request($question, $blocks, $problems));
            $this->trace('attempt_end', ['attempt' => $attempt, 'outcome' => $summary === false ? 'broke' : ($summary === null ? 'failed' : 'ok'), 'summary' => is_array($summary) ? $summary : null, 'rejections' => $this->check->rejections(), 'problems' => $this->problems]);

            if ($summary === false) {
                // A rewrite that broke leaves the checked parts of the first draft, unless the reader has gone.
                return $settled !== null && ! $this->gone ? ['status' => 'ok', 'summary' => $settled] : ['status' => 'failed'];
            }

            if ($settled !== null) {
                // The rewrite counts only when it kept the same parts; its answered parts join the kept ones.
                return ['status' => 'ok', 'summary' => $summary !== null && count($summary['parts'] ?? []) === count($settled['parts']) ? $summary : $settled];
            }

            if ($summary !== null && $attempt < self::MAX_ATTEMPTS && $this->failedParts !== []) {
                $settled = $summary;
                $this->keptParts = array_filter($summary['parts'], fn (array $part): bool => $part['kind'] !== 'unanswered');
                $this->keptExtraFatwaIds = $summary['extraFatwaIds'];
                $this->keptPartCount = count($summary['parts']);
                $this->keptLabels = array_column($summary['parts'], 'label');
                $number = fn (array $parts): string => implode(', ', array_map(fn (int $part): string => (string) ($part + 1), $parts));
                $problems = [...$this->problems, [
                    'item' => 'parts',
                    'reason' => 'part '.$number(array_keys($this->keptParts)).' passed and is kept as it is; part '.$number($this->failedParts)
                        .' was rejected. Write the same parts line again, then only the lines of part '.$number($this->failedParts)
                        .' (with their "for"), each from the sentences that state its answer, and nothing for the kept part',
                ]];
                Log::info('Summary part rewritten', ['parts' => count($summary['parts']), 'failed' => count($this->failedParts)]);

                continue;
            }

            if ($summary !== null) {
                return ['status' => 'ok', 'summary' => $summary];
            }

            $problems = $this->problems;
            $this->logRejectedDraft($attempt, $problems);
        }

        return ['status' => 'failed'];
    }

    /**
     * One streamed draft, checked item by item: the final summary, null when nothing usable survived, or false when
     * the model, the judge or the translation failed.
     *
     * @return array<string, mixed>|false|null
     */
    private function attempt(string $request): array|false|null
    {
        // A rewrite abandons whatever the earlier draft still had running.
        $this->tasks->cancel();
        $this->attemptNumber++;
        $this->labels = [];
        $this->shownLabels = [];
        $this->labelsSettled = true;
        $this->labelPairs = [];
        $this->begun = false;
        $this->headers = [];
        $this->items = [];
        $this->reached = [];
        $this->currentPart = 0;
        $this->ended = false;
        $this->fatal = false;
        $this->gateOpen = [];
        $this->failedParts = [];
        $this->outOfOrder = [];
        $this->problems = [];
        $this->stagesSent = [];
        $this->check->prepare($this->sourceTexts, $this->names);
        $this->stage('writing');

        $model = (string) config('fatwarag.openrouter.models.summary');
        $lines = new DraftLines;
        $startedAt = hrtime(true);
        $end = null;

        try {
            foreach ($this->stream($request, model: $model) as $event) {
                if ($event instanceof TextDelta) {
                    foreach ($lines->push($event->delta) as $line) {
                        $this->accept($line);
                    }
                } elseif ($event instanceof StreamEnd) {
                    $end = $event;
                }

                $this->advance();

                if ($this->fatal) {
                    // The reader left, the time ran out or a check failed: the draft is stopped, but what it took is
                    // counted (from the provider when it reported, else estimated), so stopping can't hide spend.
                    $end === null
                        ? $this->recordStopped($request, $lines, $model, AiSpend::millisecondsSince($startedAt))
                        : $this->recordCost($end, $model, AiSpend::millisecondsSince($startedAt));

                    return false;
                }

                if ($this->doomed()) {
                    // Nothing this draft can still bring would save it: stop it and rewrite at once. The tokens
                    // received are counted, estimated from their length (the provider reports none for a stopped stream).
                    $this->recordStopped($request, $lines, $model, AiSpend::millisecondsSince($startedAt));
                    Log::info('Summary draft stopped early: it can no longer pass');

                    return $this->finalSummary();
                }
            }
        } catch (Throwable $exception) {
            if ($lines->received() !== '') {
                $this->recordStopped($request, $lines, $model, AiSpend::millisecondsSince($startedAt));
            }

            // The message can echo the request, which holds the question; questions are never logged.
            $this->alerts->raise('Summary failed', 'summary:upstream', 'The summary model failed; answers show the fatwa list alone.', [
                'error' => $exception::class,
            ]);

            return false;
        }

        $this->alerts->resolve('summary:upstream');
        $this->recordCost($end, $model, AiSpend::millisecondsSince($startedAt));

        if ($end?->reason === FinishReason::Length->value) {
            // Complete lines are still read; a line cut off by the cap isn't. Logged so the cap can be raised.
            Log::warning('Summary draft hit its output cap', ['max_tokens' => $this->maxTokens()]);
        }

        foreach ($lines->finish() as $line) {
            $this->accept($line);
        }

        $this->ended = true;
        $this->advance();

        // Wait for the checks still running, unless the draft can no longer pass whatever they say.
        while (! $this->fatal && $this->tasks->busy() && ! $this->doomed()) {
            $this->tasks->pause();
            $this->advance();
        }

        if ($this->fatal) {
            return false;
        }

        if ($this->outOfOrder !== []) {
            Log::info('Summary draft lines out of order were ignored', ['count' => array_sum($this->outOfOrder), 'why' => $this->outOfOrder]);
        }

        return $this->finalSummary();
    }

    /**
     * One line of the draft: the question's parts («parts», only as the very first line), a part's first line («kind»,
     * «headline»; for one part, the draft's first line) or one item, checked at once and sent to the judge.
     *
     * @param  array<string, mixed>  $line
     */
    private function accept(array $line): void
    {
        $this->trace('line', ['line' => $line]);

        if (! $this->begun && array_key_exists('parts', $line) && ! isset($line['part'])) {
            $this->begun = true;
            $this->startParts($line['parts']);

            return;
        }

        $this->begun = true;
        $part = $this->partOf($line);

        if ($part === null) {
            $this->ignore('no such part, or an earlier one');

            return;
        }

        if (isset($this->keptParts[$part])) {
            return; // a rewrite's line for a part already answered: kept as it was, never judged or translated again
        }

        if (! isset($this->headers[$part]) && array_key_exists('kind', $line) && ! isset($line['part'])) {
            $kind = $line['kind'] ?? null;
            $this->headers[$part] = [
                // Only a part may say no fatwa answers it; one part is an agreement unless it says it differs, as before.
                'kind' => $kind === 'differ' ? 'differ' : ($kind === 'none' && $this->multi() ? 'none' : 'agree'),
                'headline' => trim((string) (is_scalar($line['headline'] ?? null) ? $line['headline'] : '')),
                // The writer's flag: the fatwas hold details the summary doesn't spell out. It can only add the note.
                'details' => ($line['details'] ?? null) === true,
            ];

            return;
        }

        $section = is_string($line['part'] ?? null) ? $line['part'] : null;

        if ($section === null || ! isset(self::SECTIONS[$section])) {
            return;
        }

        $count = count(array_filter($this->items, fn (array $item): bool => $item['part'] === $part && $item['section'] === $section));

        if (($this->headers[$part]['kind'] ?? null) === 'none') {
            $this->ignore('an item of a part said to be unanswered');

            return;
        }

        if (self::SECTIONS[$section] < ($this->reached[$part] ?? 0) || (in_array($section, ['agreed', 'caution'], true) && $count > 0)) {
            $this->ignore('an earlier section, or a second agreed point or caution');

            return;
        }

        $this->reached[$part] = self::SECTIONS[$section];
        $rejectedBefore = count($this->check->rejections());
        $resolved = $this->sentences->resolve($line);
        $checked = $this->check->item($section, $resolved, $section === 'view' && ! filled($line['title'] ?? null) ? 'view '.($count + 1) : null);
        // An agreement shows no views and no caution, so they aren't judged.
        $skipped = $checked !== null && ($this->headers[$part]['kind'] ?? null) === 'agree' && in_array($section, ['view', 'caution'], true);
        $key = in_array($section, ['agreed', 'caution'], true) ? $section : $section.$count;

        $this->items[] = [
            'part' => $part,
            'section' => $section,
            'key' => $this->multi() ? 'p'.($part + 1).'_'.$key : $key,
            'checked' => $checked,
            'state' => $checked === null ? 'failed' : ($skipped ? 'skipped' : 'checking'),
            'judged' => null,
            'rejudging' => false,
            'headlineSupported' => false,
            'headlineJudged' => false,
            'verifying' => false,
            'fields' => [],
            'translations' => null,
            'english' => [],
            'emitted' => false,
        ];

        if ($this->trace !== null) {
            // What the claims judge reads is traced for a rejected item too, so diagnosis can ask the judge about it.
            $this->trace('item', [
                'index' => array_key_last($this->items),
                'part' => $part,
                'section' => $section,
                'key' => $this->items[array_key_last($this->items)]['key'],
                'resolved' => $resolved,
                'checked' => $checked,
                'skipped' => $skipped,
                'rejections' => array_slice($this->check->rejections(), $rejectedBefore),
                'claim' => $this->claim($checked ?? $resolved),
            ]);
        }

        if (! empty($checked['_pruned'])) {
            // Never the text: only that sentences were pruned and how many.
            Log::info('Summary item pruned', ['by' => 'checker', 'sentences' => $checked['_pruned']]);
        }

        if ($checked !== null && ! $skipped) {
            $this->startChecks(array_key_last($this->items));
        }
    }

    private function ignore(string $why): void
    {
        $this->outOfOrder[$why] = ($this->outOfOrder[$why] ?? 0) + 1;
    }

    /**
     * Whether the draft answers the question in several parts.
     */
    private function multi(): bool
    {
        return $this->labels !== [];
    }

    /**
     * The part a line belongs to: always the first for one part; else its «for» (1-based), which may not go back to an
     * earlier part than the draft is writing. A line that leaves out «for» belongs where the draft is: an item to the
     * part being written, a part's first line to the next part without one. Null when the line names no valid part, or
     * an earlier one.
     *
     * @param  array<string, mixed>  $line
     */
    private function partOf(array $line): ?int
    {
        if (! $this->multi()) {
            return 0;
        }

        $for = $line['for'] ?? null;

        if ($for === null) {
            $isHeader = array_key_exists('kind', $line) && ! isset($line['part']);
            $for = $isHeader && isset($this->headers[$this->currentPart]) ? $this->currentPart + 2 : $this->currentPart + 1;
        }

        $part = is_int($for) || (is_string($for) && ctype_digit($for)) ? (int) $for - 1 : -1;

        if ($part < 0 || $part >= count($this->labels) || $part < $this->currentPart) {
            return null;
        }

        return $this->currentPart = $part;
    }

    /**
     * The draft's parts line: two or more short labels phrased from the question. Fewer is one part, as before. A label
     * is shown only when it passes its check (short, no ruling, no negation, no number the question doesn't have, no
     * source's name), else a neutral one («الجزء الأول من السؤال»); for English its translation is checked too.
     */
    private function startParts(mixed $labels): void
    {
        $labels = array_values(array_filter(
            array_map(fn (mixed $label): string => is_string($label) ? trim($label) : '', is_array($labels) ? $labels : []),
            fn (string $label): bool => $label !== '',
        ));

        if (count($labels) < 2) {
            return;
        }

        $this->labels = array_slice($labels, 0, self::MAX_PARTS);
        $fields = [];

        if ($this->keptParts !== []) {
            // A rewrite of failed parts keeps the first draft's labels, already checked (and translated): nothing is
            // checked or translated again.
            $this->shownLabels = count($this->labels) === $this->keptPartCount
                ? $this->keptLabels
                : array_map(fn (int $part): string => self::neutralLabel($part, $this->locale), array_keys($this->labels));

            return;
        }

        foreach ($this->labels as $part => $label) {
            $safe = $this->labelSafe($label);
            $this->shownLabels[$part] = $safe && $this->locale === 'ar' ? $label : self::neutralLabel($part, $this->locale);

            if ($safe) {
                $fields["label{$part}"] = $label;
            } else {
                Log::info('Summary part label failed its check', ['part' => $part + 1]);
            }
        }

        if ($this->locale === 'en' && $fields !== []) {
            $this->labelsSettled = false;
            $glossary = $this->glossary;
            $this->tasks->start("{$this->attemptNumber}:-1:labels", static fn (): ?array => app(SummaryTranslator::class)->draft($fields, $glossary), $this->stepEstimate(SummaryTranslator::PURPOSE, $this->translator->instructions().json_encode([$fields, $glossary], JSON_UNESCAPED_UNICODE), $this->translator->maxTokens()));
        }
    }

    /**
     * A part's label only names what the question asks, so it can carry no claim of its own: a few words, no ruling or
     * negation, no number the question doesn't have, no source's or mufti's name.
     */
    private function labelSafe(string $label): bool
    {
        $words = Lexicon::tokens($label);
        $numbers = app(NumberReader::class);

        return $words !== [] && count($words) <= self::MAX_LABEL_WORDS && mb_strlen($label) <= 120
            && $this->polarity->rulings($label) === []
            && $this->polarity->negations($label) === 0
            && array_diff($numbers->values($label), $numbers->values($this->question)) === []
            && ! $this->check->mentions($label, array_merge(...array_values($this->names) ?: [[]]));
    }

    /**
     * The neutral label of a part whose own label isn't shown.
     */
    public static function neutralLabel(int $part, string $locale): string
    {
        $ordinals = ['الأول', 'الثاني', 'الثالث', 'الرابع'];

        return $locale === 'en' ? 'Part '.($part + 1).' of the question' : 'الجزء '.($ordinals[$part] ?? (string) ($part + 1)).' من السؤال';
    }

    /**
     * The headline of a summary in parts: neutral framing, never a claim.
     */
    public static function partsHeadline(int $parts, string $locale): string
    {
        if ($locale === 'en') {
            return 'Your question has '.(['', '', 'two', 'three', 'four'][$parts] ?? (string) $parts).' parts';
        }

        return match ($parts) {
            2 => 'سؤالك من جزأين',
            3 => 'سؤالك من ثلاثة أجزاء',
            4 => 'سؤالك من أربعة أجزاء',
            default => 'سؤالك من عدة أجزاء',
        };
    }

    /**
     * Sends a checked item to the judge and, for English, to the translator, side by side.
     */
    private function startChecks(int $i): void
    {
        $item = $this->items[$i];
        $this->stage('checking');

        if (! $this->claimsJudge()) {
            // Owner decision: no claims judge. The item stands on the lexical checks it already passed; English
            // still goes through the translation and its judge.
            $this->items[$i]['judged'] = true;
            $this->items[$i]['headlineSupported'] = true;
            $this->items[$i]['headlineJudged'] = true;

            if ($this->locale === 'ar') {
                $this->items[$i]['state'] = 'passed';
            }
        } else {
            $this->judgeClaims($i, $item);
        }

        if ($this->locale === 'en') {

            $fields = $this->fieldsOf($item);
            $glossary = $this->glossary;
            $this->items[$i]['fields'] = $fields;
            $this->stage('translating');
            $this->tasks->start("{$this->attemptNumber}:{$i}:translate", static fn (): ?array => app(SummaryTranslator::class)->draft($fields, $glossary), $this->stepEstimate(SummaryTranslator::PURPOSE, $this->translator->instructions().json_encode([$fields, $glossary], JSON_UNESCAPED_UNICODE), $this->translator->maxTokens()));
        }
    }

    /**
     * Whether items go to the Haiku claims judge (`fatwarag.summary.claims_judge`, on by owner decision).
     */
    private function claimsJudge(): bool
    {
        return (bool) config('fatwarag.summary.claims_judge', true);
    }

    /**
     * Sends a checked item to the claims judge; an agreement's headline is judged against the agreed point's own
     * support, in a call of its own beside it.
     *
     * @param  array<string, mixed>  $item
     */
    private function judgeClaims(int $i, array $item): void
    {
        $claims = [$item['key'] => $this->claim($item['checked'])];
        $this->tasks->start("{$this->attemptNumber}:{$i}:claims", static fn (): ?array => app(EntailmentJudge::class)->claims($claims), $this->judgeEstimate($claims));

        if ($this->judgesHeadline($item)) {
            $headline = ['headline' => ['claim' => $this->headers[$item['part']]['headline'], 'quotes' => $claims[$item['key']]['quotes'], 'context' => $claims[$item['key']]['context']]];
            $this->tasks->start("{$this->attemptNumber}:{$i}:headline", static fn (): ?array => app(EntailmentJudge::class)->claims($headline), $this->judgeEstimate($headline));
        }
    }

    /**
     * Collects finished checks, moves each item on, and sends what is ready.
     */
    private function advance(): void
    {
        $outOfTime = AiSpend::millisecondsSince($this->startedAt) > (float) config('fatwarag.summary.wall_seconds', 300) * 1000;

        if ($outOfTime || ($this->alive !== null && ! ($this->alive)())) {
            // The reader has gone, or the summary ran past its wall-clock limit: abandon the checks still running
            // (their estimated cost is recorded) and stop as failed, with no rewrite.
            if ($outOfTime) {
                Log::warning('Summary stopped: past its time limit');
            }

            $this->gone = true;
            $this->fatal = true;
            $this->tasks->cancel();

            return;
        }

        foreach ($this->tasks->poll() as $key => $outcome) {
            [$attempt, $i, $step] = explode(':', $key);

            if ((int) $attempt !== $this->attemptNumber) {
                continue; // a step of a draft already given up on
            }

            $result = $outcome['ok'] && is_array($outcome['result']) ? $outcome['result'] : null;
            $this->trace('step', ['index' => (int) $i, 'step' => $step, 'result' => $result, 'fields' => (int) $i >= 0 ? ($this->items[(int) $i]['fields'] ?? []) : $this->labels]);
            (int) $i < 0 ? $this->settleLabels($step, $result) : $this->settle((int) $i, $step, $result);

            if ($this->fatal) {
                return;
            }
        }

        $this->emitReady();
    }

    /**
     * @param  'claims'|'headline'|'translate'|'verify'|string  $step
     * @param  array<string, mixed>|null  $result  null when the call failed
     */
    private function settle(int $i, string $step, ?array $result): void
    {
        if ($step === 'reclaims' && $result === null) {
            // The pruned item's judge couldn't answer: that item is dropped (never shown pruned); the rest stands.
            $this->items[$i]['state'] = 'failed';

            return;
        }

        if ($result === null) {
            $this->fatal = true; // the judge or the translation couldn't answer: fail closed

            return;
        }

        $item = &$this->items[$i];

        if (in_array($step, ['claims', 'reclaims'], true)) {
            // A pruned item stands only if PruneJudge finds it supported and that nothing it left out changes the rest.
            $item['judged'] = $step === 'reclaims' ? PruneJudge::passes($result, $item['key']) : EntailmentJudge::supported($result, $item['key']);

            if (! $item['judged'] && $step === 'claims' && $this->rejudge($i, EntailmentJudge::failingSentences($result, $item['key']))) {
                return; // the sentences the judge named are pruned, and the rest is judged once more
            }

            if (! $item['judged']) {
                $item['state'] = 'failed';
                $this->problems[] = ['item' => $this->labelOf($item), 'reason' => 'not supported by its own quotes: '.($result[$item['key']]['reason'] ?? 'no verdict')];
            } elseif ($this->locale === 'ar') {
                $item['state'] = 'passed';
            } else {
                $this->verifyWhenReady($i);
            }
        } elseif ($step === 'headline') {
            $item['headlineSupported'] = EntailmentJudge::supported($result, 'headline');
            $item['headlineJudged'] = true;
            $this->verifyWhenReady($i);
        } elseif ($step === 'translate' && $item['rejudging']) {
            return; // the translation of the text before it was pruned: the pruned text has its own
        } elseif (in_array($step, ['translate', 'retranslate'], true)) {
            $item['translations'] = $result;
            $this->verifyWhenReady($i);
        } elseif ($step === 'verify') {
            $english = [];

            foreach ($item['fields'] as $id => $arabic) {
                if (EntailmentJudge::supported($result, $id) && isset($item['translations'][$arabic])) {
                    $english[$id] = $item['translations'][$arabic];
                }
            }

            $item['english'] = $english;
            $isPoint = in_array($item['section'], ['agreed', 'view'], true);
            $complete = $isPoint
                ? (! empty($item['checked']['_relabel']) || isset($english["{$item['key']}.title"])) && isset($english["{$item['key']}.body"])
                : isset($english["{$item['key']}.text"]);
            $item['state'] = $complete ? 'passed' : 'failed';
        }
    }

    /**
     * When the claims judge names the sentences of an item it found unsupported, they are removed and what is left goes
     * through every lexical check again (GroundingCheck::prune()) and then, once, PruneJudge: the kept text with the
     * removed sentences and the evidence, asked whether the rest still follows and whether the removal dropped a
     * condition, left a reference pointing elsewhere or changed a holder (for English, with its own translation). Any
     * yes, or a failed call, drops the item. False when nothing meaningful would be left or the rest fails a check: the
     * item then fails.
     *
     * @param  list<int>  $sentences  1-based, as the judge numbered them
     */
    private function rejudge(int $i, array $sentences): bool
    {
        $item = $this->items[$i];

        // Off by default for v1 (`fatwarag.summary.prune`): the item then fails whole, as any failing item does.
        if ($sentences === [] || $item['rejudging'] || ! config('fatwarag.summary.prune', false)) {
            return false;
        }

        $pruned = $this->check->prune($item['section'], $item['checked'], $sentences);

        if ($pruned === null) {
            return false;
        }

        Log::info('Summary item pruned', ['by' => 'judge', 'sentences' => count($sentences)]);
        $this->items[$i] = [...$item, 'checked' => $pruned, 'judged' => null, 'rejudging' => true, 'translations' => null];
        $item = $this->items[$i];
        $claims = [$item['key'] => [...$this->claim($pruned), 'removed' => $pruned['_removed'] ?? []]];
        $this->tasks->start("{$this->attemptNumber}:{$i}:reclaims", static fn (): ?array => app(PruneJudge::class)->judge($claims), $this->judgeEstimate($claims));

        if ($this->locale === 'en') {
            $fields = $this->fieldsOf($item);
            $glossary = $this->glossary;
            $this->items[$i]['fields'] = $fields;
            $this->tasks->start("{$this->attemptNumber}:{$i}:retranslate", static fn (): ?array => app(SummaryTranslator::class)->draft($fields, $glossary), $this->stepEstimate(SummaryTranslator::PURPOSE, $this->translator->instructions().json_encode([$fields, $glossary], JSON_UNESCAPED_UNICODE), $this->translator->maxTokens()));
        }

        return true;
    }

    /**
     * For English, the part labels' steps: their drafted translation goes to the translation judge, and each label the
     * judge confirms is shown in English (else its neutral label stays).
     *
     * @param  array<string, mixed>|null  $result  null when the call failed
     */
    private function settleLabels(string $step, ?array $result): void
    {
        if ($result === null) {
            $this->fatal = true; // fail closed, as for any translation

            return;
        }

        if ($step === 'labels') {
            $pairs = [];

            foreach ($this->labels as $part => $label) {
                if (isset($result[$label])) {
                    $pairs["label{$part}"] = ['arabic' => $label, 'english' => $result[$label]];
                }
            }

            if ($pairs === []) {
                $this->labelsSettled = true;

                return;
            }

            $this->labelPairs = $pairs;
            $this->tasks->start("{$this->attemptNumber}:-1:labelcheck", static fn (): ?array => app(EntailmentJudge::class)->translations($pairs), $this->judgeEstimate($pairs));

            return;
        }

        foreach ($this->labelPairs as $id => $pair) {
            if (EntailmentJudge::supported($result, $id)) {
                $this->shownLabels[(int) substr($id, 5)] = $pair['english'];
            }
        }

        $this->labelsSettled = true;
    }

    /**
     * For English: once the claims judge kept the item, its translation is drafted and (for an agreed point) its
     * headline judged, the translation judge checks it.
     */
    private function verifyWhenReady(int $i): void
    {
        $item = $this->items[$i];

        if ($this->locale !== 'en' || $item['state'] !== 'checking' || $item['judged'] !== true || $item['translations'] === null
            || ($this->judgesHeadline($item) && ! $item['headlineJudged']) || $item['verifying']) {
            return;
        }

        $this->items[$i]['verifying'] = true;
        $this->verifyTranslation($i);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function judgesHeadline(array $item): bool
    {
        return $item['section'] === 'agreed' && ($this->headers[$item['part']]['kind'] ?? null) === 'agree' && ($this->headers[$item['part']]['headline'] ?? '') !== '';
    }

    /**
     * The translation judge for an item the claims judge kept: does each English field say exactly its Arabic?
     */
    private function verifyTranslation(int $i): void
    {
        $item = $this->items[$i];
        $pairs = [];

        foreach ($item['fields'] as $id => $arabic) {
            if ($id === 'headline' && ! $item['headlineSupported']) {
                continue; // the Arabic headline won't be shown, so neither is its English
            }

            if (isset($item['translations'][$arabic])) {
                $pairs[$id] = ['arabic' => $arabic, 'english' => $item['translations'][$arabic]];
            }
        }

        if ($pairs === []) {
            $this->items[$i]['state'] = 'failed';

            return;
        }

        $this->tasks->start("{$this->attemptNumber}:{$i}:verify", static fn (): ?array => app(EntailmentJudge::class)->translations($pairs), $this->judgeEstimate($pairs));
    }

    /**
     * Sends, in display order, every item that has passed and whose place is settled, part by part. Nothing of a part is
     * sent until it can no longer fail: an agreement once its agreed point has passed, a disagreement once two views
     * have (and, for English parts, once their labels are checked).
     */
    private function emitReady(): void
    {
        if ($this->multi() && ! $this->labelsSettled) {
            return;
        }

        if ($this->keptParts !== [] && count($this->labels) !== $this->keptPartCount) {
            return; // a rewrite that changed the parts is never used
        }

        foreach ($this->multi() ? array_keys($this->labels) : [0] as $part) {
            $this->emitPart($part);
        }
    }

    private function emitPart(int $part): void
    {
        if (isset($this->keptParts[$part])) {
            return; // already sent with the first draft
        }

        $kind = $this->effectiveKind($part);

        if ($kind === null || $kind === 'none') {
            return;
        }

        $partial = $kind === 'differ' && $this->passed('view', $part) < 2;

        if (! isset($this->gateOpen[$part])) {
            if (! $this->canOpen($part, $kind, $partial)) {
                return;
            }

            $this->gateOpen[$part] = true;
        }

        foreach ($kind === 'agree' ? ['agreed', 'preference'] : ['agreed', 'view', 'preference', 'caution'] as $section) {
            $index = 0;

            foreach ($this->items as $i => $item) {
                if ($item['part'] !== $part || $item['section'] !== $section) {
                    continue;
                }

                if ($item['state'] === 'checking') {
                    return;
                }

                if ($item['state'] === 'passed' && ! ($partial && $section === 'view' && ! $this->check->namesWhoseView($item['checked']))) {
                    if (! $item['emitted']) {
                        $this->items[$i]['emitted'] = true;
                        $event = ['section' => $section, 'index' => $index, 'item' => $this->shown($item, $index)];
                        $this->send('item', $this->multi() ? [...$event, 'part' => $part, 'partLabel' => $this->shownLabels[$part]] : $event);
                    }

                    $index++;
                }
            }

            if (! $this->sectionDone($part, $section)) {
                return; // more of this section may still come
            }
        }
    }

    /**
     * Whether a part's items may start to be sent: once the part can no longer fail. An agreement once its agreed point
     * has passed, or, when that failed, once a preference has; a disagreement once two views have, or, when fewer stand
     * (only a view that says whose it is counts, the others views being pointed to), once any of its items has.
     */
    private function canOpen(int $part, string $kind, bool $partial): bool
    {
        if ($kind === 'agree') {
            return $this->passed('agreed', $part) > 0
                || ($this->settled('agreed', $part) && $this->passed('preference', $part) > 0);
        }

        if (! $partial) {
            return true;
        }

        foreach ($this->items as $item) {
            if ($item['part'] === $part && $item['state'] === 'passed' && ($item['section'] !== 'view' || $this->check->namesWhoseView($item['checked']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a section of a part is written and none of its items is still being checked.
     */
    private function settled(string $section, int $part): bool
    {
        if (! $this->sectionDone($part, $section)) {
            return false;
        }

        foreach ($this->items as $item) {
            if ($item['part'] === $part && $item['section'] === $section && $item['state'] === 'checking') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the draft has moved past a part: it ended, or it writes a later part.
     */
    private function partDone(int $part): bool
    {
        return $this->ended || $this->currentPart > $part;
    }

    /**
     * Whether no more lines of a section of a part can come.
     */
    private function sectionDone(int $part, string $section): bool
    {
        return $this->partDone($part) || ($this->reached[$part] ?? 0) > self::SECTIONS[$section];
    }

    /**
     * What a part will be, once that is certain: an agreement, a disagreement (two views have passed, or its views are
     * settled with fewer standing: shown with what survived and a line pointing to the other views), a disagreement the
     * draft gave fewer than two views beside an agreed point that passed (shown as an agreement), `none` when no fatwa
     * answers it, or null while undecided.
     */
    private function effectiveKind(int $part): ?string
    {
        $header = $this->headers[$part] ?? null;

        if ($header === null) {
            return null;
        }

        if ($header['kind'] !== 'differ') {
            return $header['kind'];
        }

        if ($this->passed('view', $part) >= 2) {
            return 'differ';
        }

        if (! $this->settled('view', $part)) {
            return null;
        }

        $drafted = count(array_filter($this->items, fn (array $item): bool => $item['part'] === $part && $item['section'] === 'view'));

        if ($drafted >= 2) {
            return 'differ';
        }

        if ($this->passed('agreed', $part) > 0) {
            return 'agree';
        }

        return $this->settled('agreed', $part) ? 'differ' : null;
    }

    /**
     * Whether the draft can no longer give a summary, whatever it still writes: every part can no longer pass.
     */
    private function doomed(): bool
    {
        if ($this->keptParts !== [] && count($this->labels) !== $this->keptPartCount) {
            return $this->begun; // a rewrite that changed the parts can't be used
        }

        foreach ($this->multi() ? array_keys($this->labels) : [0] as $part) {
            if (! $this->partDoomed($part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a part can no longer give anything: an agreement whose agreed point and preferences all failed, a
     * disagreement the draft has moved past with none of its items standing, a part no fatwa answers, or (in parts) one
     * the draft passed by.
     */
    private function partDoomed(int $part): bool
    {
        if (isset($this->keptParts[$part])) {
            return true; // already answered: nothing more is needed from this draft
        }

        $header = $this->headers[$part] ?? null;

        if ($header === null) {
            return $this->multi() && $this->partDone($part);
        }

        if ($header['kind'] === 'none') {
            return true;
        }

        $standing = fn (string $section): int => count(array_filter($this->items, fn (array $item): bool => $item['part'] === $part
            && $item['section'] === $section && in_array($item['state'], ['checking', 'passed'], true)));
        $sections = $header['kind'] === 'agree' ? ['agreed', 'preference'] : ['agreed', 'view', 'preference', 'caution'];

        foreach ($sections as $section) {
            if ($standing($section) > 0) {
                return false;
            }
        }

        // Nothing stands yet: lost once no more of its shown sections can come.
        return $this->sectionDone($part, end($sections));
    }

    private function passed(string $section, int $part = 0): int
    {
        return count(array_filter($this->items, fn (array $item): bool => $item['part'] === $part && $item['section'] === $section && $item['state'] === 'passed'));
    }

    /**
     * The final Summary from the items that passed every check, in the public shape (web/src/types/answer.ts). In
     * parts, each part is checked on its own; one that fails, or that no fatwa answers, is `unanswered`, and the
     * summary fails only when every part does.
     *
     * @return array<string, mixed>|null
     */
    private function finalSummary(): ?array
    {
        if (! $this->multi()) {
            if (! isset($this->headers[0])) {
                $this->problems[] = ['item' => 'summary', 'reason' => 'the output was not a summary (no first line with kind and headline)'];

                return null;
            }

            $result = $this->partResult(0);

            if ($result === null) {
                $this->problems = [...$this->check->rejections(), ...$this->problems];

                return null;
            }

            return [...array_diff_key($result, ['items' => true]), 'extraFatwaIds' => $this->extraFatwaIds($result['items'])];
        }

        $parts = [];
        $items = [];

        foreach (array_keys($this->labels) as $part) {
            if (isset($this->keptParts[$part])) {
                $parts[] = $this->keptParts[$part];

                continue;
            }

            $result = $this->partResult($part);
            $items = [...$items, ...($result['items'] ?? [])];
            $parts[] = ['label' => $this->shownLabels[$part], ...($result === null
                ? ['kind' => 'unanswered', 'headline' => null, 'agreed' => null, 'views' => [], 'preferences' => [], 'caution' => null, 'note' => null]
                : array_diff_key($result, ['items' => true]))];

            if ($result === null && ($this->headers[$part]['kind'] ?? null) !== 'none') {
                $this->failedParts[] = $part;
            }
        }

        $answered = array_filter($parts, fn (array $part): bool => $part['kind'] !== 'unanswered');

        if ($answered === []) {
            $this->problems = [...$this->check->rejections(), ...$this->problems];
            $this->problems[] = ['item' => 'summary', 'reason' => 'no part of the question was answered: give each part its own first line with "for", kind and headline, then its items with the same "for"'];

            return null;
        }

        if (count($answered) < count($parts)) {
            // Never the question or the labels: only how many parts went unanswered.
            Log::info('Summary part left unanswered', ['parts' => count($parts), 'unanswered' => count($parts) - count($answered)]);
        }

        return [
            'kind' => in_array('differ', array_column($answered, 'kind'), true) ? 'differ' : 'agree',
            'headline' => self::partsHeadline(count($parts), $this->locale),
            'agreed' => null,
            'views' => [],
            'preferences' => [],
            'caution' => null,
            'note' => null,
            'parts' => $parts,
            'extraFatwaIds' => array_values(array_unique([...$this->keptExtraFatwaIds, ...$this->extraFatwaIds($items)])),
        ];
    }

    /**
     * One part from its items that passed every check, through the same final checks as a one-part summary, or null
     * when it can't stand (with its reasons among the checker's rejections).
     *
     * @return array{kind: string, headline: string, agreed: array<string, mixed>|null, views: list<array<string, mixed>>, preferences: list<array<string, mixed>>, caution: array<string, mixed>|null, note: string|null, items: list<array<string, mixed>>}|null
     */
    private function partResult(int $part): ?array
    {
        $header = $this->headers[$part] ?? null;

        if ($header === null || $header['kind'] === 'none') {
            return null;
        }

        $passed = fn (string $section): array => array_values(array_filter($this->items, fn (array $item): bool => $item['part'] === $part && $item['section'] === $section && $item['state'] === 'passed'));
        $agreedItem = $passed('agreed')[0] ?? null;
        $viewItems = $passed('view');

        if ($header['kind'] === 'differ' && count($viewItems) < 2) {
            // One side standing is shown only when it says whose it is (as GroundingCheck::finish() keeps it).
            $viewItems = array_values(array_filter($viewItems, fn (array $item): bool => $this->check->namesWhoseView($item['checked'])));
        }
        $preferenceItems = $passed('preference');
        $cautionItem = $passed('caution')[0] ?? null;

        $checked = $this->check->finish(
            $header,
            $agreedItem['checked'] ?? null,
            array_column($viewItems, 'checked'),
            array_column($preferenceItems, 'checked'),
            $cautionItem['checked'] ?? null,
            count(array_filter($this->items, fn (array $item): bool => $item['part'] === $part && $item['section'] === 'view')),
            $this->lostAny($part, $header['kind']),
            $header['details'] ?? false,
        );

        if ($checked === null) {
            return null;
        }

        $differ = $checked['kind'] === 'differ';
        $agreed = $agreedItem ? $this->shown($agreedItem, 0) : null;
        $views = $differ ? array_map(fn (array $item, int $i): array => $this->shown($item, $i), $viewItems, array_keys($viewItems)) : [];
        $headline = (string) $checked['headline'];

        if ($checked['headline'] === GroundingCheck::SOURCES_SAY_HEADLINE) {
            // Only part of the sources' answer passed (one side, or preferences alone): a neutral headline.
            $headline = $this->locale === 'en' ? SummaryTranslator::SOURCES_SAY_HEADLINE : GroundingCheck::SOURCES_SAY_HEADLINE;
        } elseif (! $differ) {
            // The model's headline stands only when it passed its checks and the judge; else the agreed title.
            $ownHeadline = $headline !== $checked['agreed']['title'] && $headline === $header['headline'] && $agreedItem['headlineSupported'];
            $headline = $ownHeadline && $this->locale === 'en' ? ($agreedItem['english']['headline'] ?? null) : ($ownHeadline ? $headline : null);
            $headline ??= $agreed['title'];
        } elseif ($this->locale === 'en') {
            $headline = SummaryTranslator::differHeadline(count($views));
        }

        return [
            'kind' => $checked['kind'],
            'headline' => $headline,
            'agreed' => $agreed,
            'views' => $views,
            'preferences' => array_map(fn (array $item, int $i): array => $this->shown($item, $i), $preferenceItems, array_keys($preferenceItems)),
            'caution' => $differ && $cautionItem ? $this->shown($cautionItem, 0) : null,
            'note' => $checked['note'],
            'items' => array_values(array_filter([$agreedItem, ...$viewItems, ...$preferenceItems, $differ ? $cautionItem : null])),
        ];
    }

    /**
     * Whether any item of a part, in the sections it shows (an agreement shows no views or caution), was dropped or
     * pruned: the summary then points to the fatwas for the details.
     */
    private function lostAny(int $part, string $kind): bool
    {
        foreach ($this->items as $item) {
            if ($item['part'] !== $part || ($kind === 'agree' && in_array($item['section'], ['view', 'caution'], true))) {
                continue;
            }

            if ($item['state'] === 'failed' || ! empty($item['checked']['_pruned'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The cross-referenced fatwas the shown items cite.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function extraFatwaIds(array $items): array
    {
        $cited = array_merge(...array_map(fn (array $item): array => $item['checked']['citations'], $items) ?: [[]]);

        return array_values(array_intersect(array_values(array_unique($cited)), $this->crossRefIds));
    }

    /**
     * An item as the reader sees it at its place, in the reader's language: the same in a streamed `item` event and
     * in the final summary.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function shown(array $item, int $index): array
    {
        $checked = $item['checked'];
        $key = $item['key'];

        if (in_array($item['section'], ['agreed', 'view'], true)) {
            $relabelled = ! empty($checked['_relabel']);
            $point = $this->presentPoint(GroundingCheck::labelled($checked, $item['section'] === 'view' ? $index : null));

            if ($this->locale === 'ar') {
                return $point;
            }

            return [
                ...$point,
                'title' => $relabelled
                    ? ($item['section'] === 'view' ? SummaryTranslator::viewLabel($index) : SummaryTranslator::AGREE_TITLE)
                    : $item['english']["{$key}.title"],
                'heldBy' => filled($point['heldBy']) ? ($item['english']["{$key}.heldBy"] ?? null) : null,
                'body' => $item['english']["{$key}.body"],
            ];
        }

        $note = ['text' => (string) ($checked['text'] ?? ''), 'citations' => $checked['citations']];

        if ($item['section'] === 'caution') {
            $note['evidence'] = $this->evidence($checked);
        }

        if ($this->locale === 'en') {
            $note['text'] = $item['english']["{$key}.text"];
        }

        return $note;
    }

    /**
     * The Arabic fields of an item to translate, by field id: a neutral label isn't translated, and an agreement's
     * headline goes with its agreed point.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, string>
     */
    private function fieldsOf(array $item): array
    {
        $checked = $item['checked'];
        $key = $item['key'];
        $fields = [];

        if ($this->judgesHeadline($item)) {
            $fields['headline'] = $this->headers[$item['part']]['headline'];
        }

        if (! in_array($item['section'], ['agreed', 'view'], true)) {
            return [...$fields, "{$key}.text" => (string) $checked['text']];
        }

        if (empty($checked['_relabel'])) {
            $fields["{$key}.title"] = (string) $checked['title'];
        }

        $fields["{$key}.body"] = (string) $checked['body'];

        if (filled($checked['heldBy'] ?? null)) {
            $fields["{$key}.heldBy"] = (string) $checked['heldBy'];
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function labelOf(array $item): string
    {
        return (string) ($item['checked']['title'] ?? $item['checked']['text'] ?? $item['section']);
    }

    private function stage(string $stage): void
    {
        if (! isset($this->stagesSent[$stage])) {
            $this->stagesSent[$stage] = true;
            $this->send('stage', ['stage' => $stage]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $event, array $data): void
    {
        if ($this->emit !== null) {
            ($this->emit)($event, $data);
        }
    }

    /**
     * @param  list<string>  $blocks
     * @param  list<array{item: string, reason: string}>  $problems  why the previous draft's items were rejected
     */
    private function request(string $question, array $blocks, array $problems = []): string
    {
        $language = 'اكتب الخلاصة بالعربية الفصحى الميسرة.';
        $retry = '';

        if ($problems !== []) {
            $retry = "\n\nA previous draft of this summary was rejected by the automatic checker:\n"
                .implode("\n", array_map(fn (array $problem): string => "- {$problem['item']}: {$problem['reason']}", array_slice($problems, 0, 12)))
                ."\nWrite it again. For every sentence that states a ruling, cite the numbered sentence of the fatwa that states that ruling, and only sentences of fatwas the item cites. Keep each point's wording close to its cited sentences, keep rulings exactly as strong as the fatwas state them, and name the source of every preference.";
        }

        return "السؤال / Question:\n{$question}\n\n{$this->partsNote()}{$language}{$retry}\n\n".implode("\n\n", $blocks);
    }

    /**
     * The question's parts as search found them, for the draft to answer in that order, each part with the fatwas found
     * for it. Empty for one part.
     */
    private function partsNote(): string
    {
        if ($this->searchParts === []) {
            return '';
        }

        $lines = array_map(function (array $part, int $i): string {
            $labels = array_values(array_filter(array_map(fn (string $id): ?string => $this->sentences->label($id), $part['fatwaIds'])));

            return ($i + 1).'. «'.$part['label'].'»'.($labels === [] ? '' : ' — الفتاوى التي وُجدت له أولًا: '.implode('، ', $labels));
        }, $this->searchParts, array_keys($this->searchParts));

        return "أجزاء السؤال: اكتب سطر parts بهذه التسميات نفسها وبهذا الترتيب، وأجب عن كل جزء مما تقوله الفتاوى، مبتدئًا بالفتاوى التي وُجدت له، ولا تقل إن جزءًا لم يُجب عنه إلا بعد أن تبحث عنه في الفتاوى كلها:\n".implode("\n", $lines)."\n\n";
    }

    /**
     * Why a draft was rejected, for calibration: the checker's reason templates and how many items the judge dropped.
     * Never the question, and never the items' text.
     *
     * @param  list<array{item: string, reason: string}>  $problems
     */
    private function logRejectedDraft(int $attempt, array $problems): void
    {
        $judged = array_filter($problems, fn (array $problem): bool => str_starts_with($problem['reason'], 'not supported by its own quotes'));
        $lexical = array_diff_key($problems, $judged);

        Log::info('Summary draft rejected', [
            'attempt' => $attempt,
            'checker' => array_count_values(array_map(fn (array $problem): string => (string) preg_replace('/\s*[(:].*$/u', '', $problem['reason']), $lexical)),
            'judge_dropped' => count($judged),
        ]);
    }

    /**
     * Whether a rewrite still fits the summary's time budget. A reader waiting longer than that is better served by
     * the fatwa list alone, which the page already shows.
     */
    private function hasTimeForRewrite(int $startedAt): bool
    {
        $elapsedMs = AiSpend::millisecondsSince($startedAt);
        $budgetMs = (int) round((float) config('fatwarag.summary.rewrite_budget_seconds', 25) * 1000);

        if ($elapsedMs <= $budgetMs) {
            return true;
        }

        // Never the question: only why the rewrite was skipped.
        Log::info('Summary rewrite skipped: the first draft failed its checks after the time budget', [
            'elapsed_ms' => $elapsedMs,
            'budget_ms' => $budgetMs,
        ]);

        return false;
    }

    /** At most this many other sentences of the cited fatwas go to the judge as mandatory context for one item. */
    private const MAX_CONFLICTS = 3;

    /**
     * An item as the judge reads it: its claim, each support sentence with its source and clause (and the sentence
     * before it when that one introduces it or reports a view), and, as mandatory context, any other sentence of the
     * cited fatwas that gives a different ruling on the same subject, so leaving it out of the support can't hide it.
     *
     * @param  array<string, mixed>  $item
     * @return array{claim: string, quotes: list<string>, context: list<string>}
     */
    private function claim(array $item): array
    {
        $claim = trim(implode("\n", array_filter([
            $item['title'] ?? null,
            filled($item['heldBy'] ?? null) ? 'Held by: '.$item['heldBy'] : null,
            // Numbered when it has two or more sentences, so the judge can name the ones it finds unsupported.
            isset($item['body']) || isset($item['text']) ? EntailmentJudge::numbered((string) ($item['body'] ?? $item['text'])) : null,
        ])));

        // Each quote carries its source and, at most, the rest of its own clause (≤ 40 words, verbatim), so the judge
        // sees a negation or attribution right next to the quote («ذهب بعضهم إلى…») but nothing the quote doesn't say.
        return [
            'claim' => $claim,
            'quotes' => $this->passages($item['support']),
            'context' => [
                ...array_map(fn (array $quote): string => $this->judgeEvidence($quote), $this->conflicts($item)),
                // The fatwa's own later turn against a cited sentence («والراجح عندنا خلاف ذلك»), even paragraphs on.
                ...array_values(array_unique(array_filter(array_map(
                    fn (array $quote): ?string => ($turn = $this->check->contraryTurn($quote)) === null ? null : '['.($this->sourceLabels[$quote['fatwaId']] ?? $quote['fatwaId']).'] «'.$turn.'» (the fatwa\'s own view, later)',
                    $item['support'],
                )))),
            ],
        ];
    }

    /**
     * Other sentences of the cited fatwas that give a different ruling on what the item rules on: a sentence that,
     * added to the support, would fail the ruling-anchoring check (Polarity::anchored()). Those sharing most words with
     * the item first.
     *
     * @param  array<string, mixed>  $item
     * @return list<array{fatwaId: string, quote: string}>
     */
    private function conflicts(array $item): array
    {
        $parts = array_values(array_filter(
            [(string) ($item['title'] ?? ''), (string) ($item['body'] ?? $item['text'] ?? '')],
            fn (string $part): bool => $part !== '' && $this->polarity->rulings($part) !== [],
        ));

        if ($parts === []) {
            return [];
        }

        $claimStems = array_flip(Lexicon::contentStems(implode(' ', $parts)));
        $found = [];

        // Fatwa by fatwa: a sentence of another fatwa in the support can't hide this fatwa's conflict. Every fatwa of the
        // support, even one a pruned item no longer shows among its citations.
        foreach (array_values(array_unique(array_column($item['support'], 'fatwaId'))) as $fatwaId) {
            $quotes = array_column(array_filter($item['support'], fn (array $quote): bool => $quote['fatwaId'] === $fatwaId), 'quote');

            foreach ($this->sentences->sentencesOf($fatwaId) as $sentence) {
                if (in_array($sentence, $quotes, true)) {
                    continue;
                }

                foreach ($parts as $part) {
                    if (! $this->polarity->anchored($part, [...$quotes, $sentence])) {
                        $found[] = ['fatwaId' => $fatwaId, 'quote' => $sentence, 'shared' => count(array_intersect_key(array_flip(Lexicon::contentStems($sentence)), $claimStems))];
                        break;
                    }
                }
            }
        }

        usort($found, fn (array $a, array $b): int => $b['shared'] <=> $a['shared']);

        return array_map(fn (array $conflict): array => ['fatwaId' => $conflict['fatwaId'], 'quote' => $conflict['quote']], array_slice($found, 0, self::MAX_CONFLICTS));
    }

    /**
     * The support as the judge reads it: for each cited sentence, the whole paragraph it sits in (and the one before
     * when it opens its paragraph), with every cited sentence marked ⟦…⟧, so a reported view, a lead-in or the fatwa's
     * own contrary ruling right after it is always in sight. One passage per paragraph, in order.
     *
     * @param  list<array{fatwaId: string, quote: string, passage?: array{key: string, sentences: array<int, string>}}>  $support
     * @return list<string>
     */
    private function passages(array $support): array
    {
        $passages = [];
        $cited = array_flip(array_column($support, 'quote'));

        foreach ($support as $quote) {
            if (! isset($quote['passage'])) {
                $passages[] = $this->judgeEvidence($quote);

                continue;
            }

            $key = $quote['fatwaId'].'#'.$quote['passage']['key'];

            if (isset($passages[$key])) {
                continue;
            }

            $text = implode(' ', array_map(
                fn (string $sentence): string => isset($cited[$sentence]) ? "⟦{$sentence}⟧" : $sentence,
                $quote['passage']['sentences'],
            ));
            $passages[$key] = '['.($this->sourceLabels[$quote['fatwaId']] ?? $quote['fatwaId'])."] {$text}";
        }

        return array_values($passages);
    }

    /**
     * One sentence as the judge reads it on its own: its source, the bare sentence and, when it differs, its clause.
     *
     * @param  array{fatwaId: string, quote: string}  $quote
     */
    private function judgeEvidence(array $quote): string
    {
        $label = '['.($this->sourceLabels[$quote['fatwaId']] ?? $quote['fatwaId']).']';
        $clause = GroundingCheck::clauseAround($this->sourceTexts[$quote['fatwaId']] ?? '', $quote['quote']);

        return Arabic::normalize($clause) === Arabic::normalize($quote['quote'])
            ? "{$label} «{$quote['quote']}»"
            : "{$label} «{$quote['quote']}» (in its clause: «{$clause}»)";
    }

    /**
     * The fatwas the top fatwas refer to, one level deep: at most two per fatwa, each once, never a top fatwa.
     *
     * @param  EloquentCollection<int, Fatwa>  $fatwas
     * @return list<array{0: Fatwa, 1: string}> the cross-referenced fatwa and the public id of the fatwa citing it
     */
    private function crossReferences(EloquentCollection $fatwas): array
    {
        $topIds = $fatwas->modelKeys();
        $links = FatwaLink::query()
            ->whereIn('fatwa_id', $topIds)
            ->whereNotNull('target_fatwa_id')
            ->whereNotIn('target_fatwa_id', $topIds)
            ->orderBy('position')
            ->get(['fatwa_id', 'target_fatwa_id'])
            ->groupBy('fatwa_id');

        $pairs = [];
        $seen = [];

        foreach ($fatwas as $fatwa) {
            foreach (($links[$fatwa->id] ?? collect())->take(self::MAX_CROSS_REFS_PER_FATWA) as $link) {
                if (! isset($seen[$link->target_fatwa_id])) {
                    $seen[$link->target_fatwa_id] = true;
                    $pairs[] = [$link->target_fatwa_id, $fatwa->public_id];
                }
            }
        }

        if ($pairs === []) {
            return [];
        }

        $targets = Fatwa::query()
            ->with(['source:id,key,name_ar,name_en', 'mufti:id,name_ar,name_en'])
            ->whereKey(array_column($pairs, 0))
            ->get()
            ->keyBy('id');

        return array_values(array_filter(array_map(
            fn (array $pair): ?array => isset($targets[$pair[0]]) ? [$targets[$pair[0]], $pair[1]] : null,
            $pairs,
        )));
    }

    /**
     * The fatwa as the model reads it, whole: its title, the question (unnumbered: support never comes from it), the
     * source's own summary and the full answer, every sentence numbered and every quoted verse and hadith marked.
     */
    private function fatwaBlock(Fatwa $fatwa, ?string $crossRefOf = null): string
    {
        $label = $this->sentences->addFatwa($fatwa);
        $sections = ['العنوان: '.$fatwa->title];
        $sections[] = "السؤال:\n".$this->questionText($fatwa->question);
        $summary = $this->sentences->paragraphs($label, 'summary');

        if ($summary !== []) {
            $sections[] = "ملخص المصدر:\n".implode("\n", array_map(SourceSentences::line(...), $summary));
        }

        $sections[] = "الجواب:\n".implode("\n", array_map(SourceSentences::line(...), $this->sentences->paragraphs($label, 'answer')));
        $block = implode("\n\n", $sections);

        // The evidence markers the model was shown: only those may be cited.
        preg_match_all('/\[(F\d+)\.E(\d+) /u', $block, $shown, PREG_SET_ORDER);

        foreach ($shown as [, $shownLabel, $marker]) {
            $this->shownMarkers[(string) $this->sentences->fatwaId($shownLabel)][(int) $marker] = true;
        }

        return sprintf(
            "<fatwa id=\"%s\"%s source=\"%s\" mufti=\"%s\">\n%s\n</fatwa>",
            $label,
            $crossRefOf !== null ? ' crossref-of="'.$this->sentences->label($crossRefOf).'"' : '',
            $fatwa->source?->name_ar,
            $fatwa->mufti?->name_ar ?? '',
            $block,
        );
    }

    /**
     * The questioner's words, plain (no sentence numbers or markers), whole.
     *
     * @param  list<list<array<string, mixed>>>|null  $paragraphs
     */
    private function questionText(?array $paragraphs): string
    {
        $lines = [];

        foreach ($paragraphs ?? [] as $paragraph) {
            $line = trim(implode('', array_map(
                fn (mixed $segment): string => is_array($segment) && in_array($segment['type'] ?? 'text', ['quran', 'hadith'], true) ? '«'.($segment['text'] ?? '').'»' : (string) (is_array($segment) ? ($segment['text'] ?? '') : ''),
                is_array($paragraph) ? $paragraph : [],
            )));

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    private function fullText(Fatwa $fatwa): string
    {
        $text = fn (?array $paragraphs): string => collect($paragraphs ?? [])
            ->filter(fn (mixed $paragraph): bool => is_array($paragraph))
            ->map(fn (array $paragraph): string => implode('', array_map(fn (mixed $segment): string => is_array($segment) ? (string) ($segment['text'] ?? '') : '', $paragraph)))
            ->implode("\n");

        // Support may come only from what the source says, never from the questioner's words (or a title echoing them).
        return implode("\n", [$text($fatwa->source_summary), $text($fatwa->answer)]);
    }

    /**
     * The names a fatwa may be attributed by: its source's and mufti's names, their distinctive words, and the usual
     * short forms.
     *
     * @return list<string>
     */
    private function namesOf(Fatwa $fatwa): array
    {
        $full = array_values(array_filter([$fatwa->source?->name_ar, $fatwa->source?->name_en, $fatwa->mufti?->name_ar, $fatwa->mufti?->name_en]));
        $names = [...$full, ...(self::SOURCE_ALIASES[$fatwa->source?->key] ?? [])];

        foreach ($full as $name) {
            foreach (Lexicon::tokens($name) as $token) {
                if (mb_strlen($token) >= 3 && ! in_array($token, self::GENERIC_NAME_WORDS, true)) {
                    $names[] = $token;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function sourceLabel(Fatwa $fatwa): string
    {
        return implode(' — ', array_filter([$fatwa->source?->name_ar, $fatwa->mufti?->name_ar]));
    }

    /**
     * Arabic → English names of the sources and muftis, so a translation names them the same way every time.
     *
     * @param  list<Fatwa>  $fatwas
     * @return array<string, string>
     */
    private function glossary(array $fatwas): array
    {
        $glossary = [];

        foreach ($fatwas as $fatwa) {
            foreach ([$fatwa->source, $fatwa->mufti] as $named) {
                if ($named?->name_ar && $named->name_en) {
                    $glossary[$named->name_ar] = $named->name_en;
                }
            }
        }

        return $glossary;
    }

    /**
     * A checked point in the public shape, its evidence filled from storage.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function presentPoint(array $item): array
    {
        return [
            'title' => (string) ($item['title'] ?? ''),
            'heldBy' => filled($item['heldBy'] ?? null) ? (string) $item['heldBy'] : null,
            'body' => (string) ($item['body'] ?? ''),
            'citations' => $item['citations'],
            'evidence' => $this->evidence($item),
        ];
    }

    /**
     * Evidence items for a point: only markers the model was shown, only from fatwas the point cites, no repeats, at
     * most two.
     *
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private function evidence(array $item): array
    {
        $markers = $this->sentences->markers();
        $evidence = [];
        $seen = [];

        foreach (is_array($item['evidence'] ?? null) ? $item['evidence'] : [] as $reference) {
            $fatwaId = (string) ($reference['fatwaId'] ?? '');
            $marker = (int) ($reference['marker'] ?? 0);
            $segment = isset($this->shownMarkers[$fatwaId][$marker]) ? ($markers[$fatwaId][$marker] ?? null) : null;

            if ($segment === null || ! in_array($fatwaId, $item['citations'], true) || isset($seen["{$fatwaId}#{$marker}"])) {
                continue;
            }

            $seen["{$fatwaId}#{$marker}"] = true;
            $evidence[] = $segment['type'] === 'quran'
                ? ['type' => 'quran', 'surah' => (int) $segment['surah'], 'ayah' => (int) $segment['ayah'], 'ayahEnd' => isset($segment['ayahEnd']) ? (int) $segment['ayahEnd'] : null]
                : [
                    'type' => 'hadith',
                    'quote' => (string) $segment['text'],
                    'quoteEn' => null,
                    'fatwaId' => $fatwaId,
                    'ref' => $this->refs[$segment['quoteKey'] ?? ''] ?? null,
                    'gradingInFatwa' => $segment['gradingInFatwa'] ?? null,
                    'gradingScope' => $segment['gradingScope'] ?? null,
                    'gradingContext' => $segment['gradingContext'] ?? null,
                ];

            if (count($evidence) === self::MAX_EVIDENCE_PER_POINT) {
                break;
            }
        }

        return $evidence;
    }

    /**
     * Dorar refs for the shown hadith quotes that have been looked up and found.
     *
     * @return array<string, array{collection: string, number: int}>
     */
    private function hadithRefs(): array
    {
        $keys = [];

        foreach ($this->sentences->markers() as $fatwaId => $segments) {
            foreach ($segments as $marker => $segment) {
                if (($segment['type'] ?? null) === 'hadith' && filled($segment['quoteKey'] ?? null) && isset($this->shownMarkers[$fatwaId][$marker])) {
                    $keys[] = $segment['quoteKey'];
                }
            }
        }

        if ($keys === []) {
            return [];
        }

        return HadithQuote::query()
            ->whereIn('quote_key', array_values(array_unique($keys)))
            ->where('status', 'found')
            ->whereNotNull('hadith_id')
            ->pluck('hadith_id', 'quote_key')
            ->map(fn (int $hadithId): array => ['collection' => 'dorar', 'number' => $hadithId])
            ->all();
    }

    /**
     * What a judge step costs at most, for when its process is stopped before it reports.
     *
     * @param  array<string, mixed>  $items
     * @return array{purpose: string, model: string, price: string, input: int, output: int}
     */
    private function judgeEstimate(array $items): array
    {
        return $this->stepEstimate(EntailmentJudge::PURPOSE, $this->judge->instructions().json_encode($items, JSON_UNESCAPED_UNICODE), $this->judge->maxTokens());
    }

    /**
     * A step's cost at most: its request at about two characters a token, and its full output allowance.
     *
     * @return array{purpose: string, model: string, price: string, input: int, output: int}
     */
    private function stepEstimate(string $purpose, string $request, int $maxOutput): array
    {
        return ['purpose' => $purpose, 'model' => (string) config('fatwarag.openrouter.models.translation'), 'price' => 'translation', 'input' => (int) ceil(mb_strlen($request) / 2), 'output' => $maxOutput];
    }

    /**
     * The cost of a draft stopped before it ended, from the length of the request and of what was received (about two
     * characters a token for Arabic, which over-counts rather than under-counts).
     */
    private function recordStopped(string $request, DraftLines $lines, string $model, int $durationMs): void
    {
        $input = (int) ceil(mb_strlen($this->instructions().$request) / 2);
        $output = (int) ceil(mb_strlen($lines->received()) / 2);

        $this->spend->record(self::PURPOSE, $model, $input, $output, AiSpend::estimate('summary', $input, $output), durationMs: $durationMs);
    }

    private function recordCost(?StreamEnd $end, string $model, int $durationMs): void
    {
        $input = (int) ($end?->usage->inputTokens ?? 0);
        $output = (int) ($end?->usage->outputTokens ?? 0);

        $this->spend->record(self::PURPOSE, $model, $input, $output, AiSpend::estimate('summary', $input, $output), durationMs: $durationMs);
    }
}
