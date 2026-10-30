<?php

namespace App\Services;

use App\Answers\SummaryWriter;
use App\Models\CommonAnswer;
use App\Models\CommonQuestion;
use App\Models\Fatwa;
use App\Search\FatwaSearch;
use App\Search\SearchUnavailable;
use Throwable;

/**
 * Generates a common question's stored answer: the real search, then the summary writer, exactly as for a visitor
 * (both record their AI cost as usual). A failed summary is stored as `failed` and serves the fatwa list alone.
 */
class CommonAnswerGenerator
{
    /** The identity the summary's step processes count against (no visitor is waiting). */
    public const CLIENT = 'common-questions';

    public const OK = 'ok';

    public const FAILED = 'failed';

    /** The monthly budget is spent: nothing is stored, and the previous answer stays. */
    public const PAUSED = 'paused';

    /** Search couldn't run or the re-ranker failed: nothing is stored, and the previous answer stays. */
    public const UNAVAILABLE = 'unavailable';

    /** No question text in this locale. */
    public const SKIPPED = 'skipped';

    /** The fingerprint of a run's one summary alert. */
    public const RUN_ALERT = 'common-answers:run';

    /** @var list<string>|null a run's failures, while one is collecting them for its summary alert */
    private ?array $runFailures = null;

    public function __construct(private FatwaSearch $search, private Alerts $alerts) {}

    /**
     * Starts a run (`common:generate`): each failure is still recorded for the admin, but the owner is told once, by
     * finishRun(), rather than once per question and locale.
     */
    public function startRun(): void
    {
        $this->runFailures = [];
    }

    /**
     * Ends a run with one alert listing what failed (sent anew every run that has failures), or, after a clean run of
     * every question, resolves it (a clean `--only` run leaves other questions' failures standing).
     */
    public function finishRun(bool $everyQuestion = true): void
    {
        $failures = $this->runFailures ?? [];
        $this->runFailures = null;

        if ($failures === [] && ! $everyQuestion) {
            return;
        }

        $this->alerts->resolve(self::RUN_ALERT);

        if ($failures === []) {
            return;
        }

        $this->alerts->raise(
            'common_answers',
            self::RUN_ALERT,
            count($failures).' common answer(s) failed in this common:generate run: '.implode('; ', $failures).'.',
            ['failures' => $failures],
            'Check the questions in the admin, then run `php artisan common:generate --only=<id> --locale=<locale>` for each.',
        );
    }

    /**
     * @return self::OK|self::FAILED|self::PAUSED|self::UNAVAILABLE|self::SKIPPED
     */
    public function generate(CommonQuestion $question, string $locale): string
    {
        $text = $question->questionFor($locale);

        if (blank($text)) {
            return self::SKIPPED;
        }

        try {
            $results = $this->search->search($text, $locale);
        } catch (SearchUnavailable) {
            $this->alert($question, $locale, 'search is unavailable');

            return self::UNAVAILABLE;
        }

        if (in_array(false, array_column($results, 'ranked'), true)) {
            $this->alert($question, $locale, 'the re-ranker failed, so the list is unranked');

            return self::UNAVAILABLE;
        }

        $publicIds = Fatwa::query()->whereKey(array_column($results, 'fatwaId'))->pluck('public_id', 'id');
        $fatwaIds = array_values(array_filter(array_map(fn (array $result): ?string => $publicIds[$result['fatwaId']] ?? null, $results)));

        if ($fatwaIds === []) {
            $this->store($question, $locale, [], ['status' => 'failed']);
            $this->alert($question, $locale, 'nothing in the sources answers it');

            return self::FAILED;
        }

        $order = array_flip($fatwaIds);
        $fatwas = Fatwa::query()->whereIn('public_id', $fatwaIds)->get()
            ->sortBy(fn (Fatwa $fatwa): int => $order[$fatwa->public_id])
            ->values();

        $hasGoodAnswer = $question->answers()->where('locale', $locale)->where('status', CommonAnswer::STATUS_OK)->exists();
        $result = app(SummaryWriter::class)->forClient(self::CLIENT)->write($text, $fatwas, $locale);

        if ($result['status'] === 'failed' && $hasGoodAnswer) {
            // A regeneration that failed gets one more try before the stored good answer is kept.
            $result = app(SummaryWriter::class)->forClient(self::CLIENT)->write($text, $fatwas, $locale);
        }

        if ($result['status'] === 'paused') {
            return self::PAUSED;
        }

        if ($result['status'] !== 'ok' && $hasGoodAnswer) {
            // Never replace a good stored answer with a failed one.
            $this->alert($question, $locale, 'regenerating it failed twice, so the stored answer is kept');

            return self::FAILED;
        }

        $this->store($question, $locale, $fatwaIds, $result);

        if ($result['status'] !== 'ok') {
            $this->alert($question, $locale, 'the summary failed its checks, so it serves the fatwa list alone');

            return self::FAILED;
        }

        $this->alerts->resolve($this->fingerprint($question, $locale));

        return self::OK;
    }

    /**
     * @param  list<string>  $fatwaIds
     * @param  array{status: string, summary?: array<string, mixed>}  $result
     */
    private function store(CommonQuestion $question, string $locale, array $fatwaIds, array $result): void
    {
        $ok = $result['status'] === 'ok' && isset($result['summary']);

        CommonAnswer::query()->updateOrCreate(
            ['common_question_id' => $question->id, 'locale' => $locale],
            [
                'fatwa_ids' => $fatwaIds,
                'summary' => $ok ? $result['summary'] : null,
                'status' => $ok ? CommonAnswer::STATUS_OK : CommonAnswer::STATUS_FAILED,
                'generated_at' => now(),
            ],
        );
    }

    /**
     * Records an answer whose generation threw, so the run's alert names it too.
     */
    public function crashed(CommonQuestion $question, string $locale, Throwable $exception): void
    {
        $this->alert($question, $locale, 'generating it threw '.$exception::class);
    }

    private function alert(CommonQuestion $question, string $locale, string $reason): void
    {
        if ($this->runFailures !== null) {
            $this->runFailures[] = "#{$question->id} {$locale}: {$reason}";
        }

        $this->alerts->raise(
            'common_answers',
            $this->fingerprint($question, $locale),
            "Common question #{$question->id} ({$locale}): {$reason}.",
            ['question_id' => $question->id, 'locale' => $locale],
            "Check the question in the admin, then run `php artisan common:generate --only={$question->id} --locale={$locale}`.",
            notify: $this->runFailures === null,
        );
    }

    private function fingerprint(CommonQuestion $question, string $locale): string
    {
        return "common-answer:{$question->id}:{$locale}";
    }
}
