<?php

namespace App\Console\Commands;

use App\Answers\EntailmentJudge;
use App\Answers\SummaryWriter;
use App\Models\AiUsage;
use App\Models\CommonQuestion;
use App\Models\Fatwa;
use App\Search\FatwaSearch;
use App\Search\SearchUnavailable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Diagnoses why common-question summaries fail: writes each summary exactly as `common:generate` does (real search, real
 * summary, its judge and translation), but stores nothing and traces every drafted line, the safeguard's verdict on
 * each item, the judge's and the translator's steps, and each draft's outcome to one JSON file per question and locale.
 * Items the safeguard rejected are also put to the claims judge afterwards (one call per draft), so a diagnosis can tell
 * what the judge would have said of them.
 *
 * Only the site's own common questions are traced, never a visitor's. It costs real AI money, so it only runs by hand.
 */
class DiagnoseCommonSummaries extends Command
{
    protected $signature = 'common:diagnose
        {out : Directory to write the traces to}
        {--only= : Only these common question ids, comma-separated}
        {--question= : Trace this question text instead of the common questions (the owner test question)}
        {--runs=1 : How many times to trace each question}
        {--locale= : Only this locale (ar or en); both by default}
        {--budget=3.5 : Stop before the next summary once this much (USD) has been spent by this run}
        {--no-shadow-judge : Don\'t ask the claims judge about the items the safeguard rejected}';

    protected $description = 'Trace common-question summaries item by item for diagnosis (costs AI money; stores nothing).';

    protected $hidden = true;

    public function handle(FatwaSearch $search): int
    {
        $out = rtrim((string) $this->argument('out'), '/');

        if (! is_dir($out) && ! mkdir($out, 0775, true) && ! is_dir($out)) {
            $this->error("Can't create {$out}.");

            return self::FAILURE;
        }

        $locales = $this->option('locale') ? [(string) $this->option('locale')] : ['ar', 'en'];
        $only = array_filter(array_map('intval', explode(',', (string) $this->option('only'))));
        $budget = (float) $this->option('budget');
        $startId = (int) AiUsage::query()->max('id');
        $spent = fn (): float => (float) AiUsage::query()->where('id', '>', $startId)->sum('cost_usd');

        $questions = filled($this->option('question'))
            ? collect([new CommonQuestion(['question_ar' => (string) $this->option('question'), 'question_en' => (string) $this->option('question')])->forceFill(['id' => 0])])
            : CommonQuestion::query()
                ->when($only !== [], fn ($query) => $query->whereKey($only))
                ->orderBy('position')
                ->get();
        $runs = max(1, (int) $this->option('runs'));

        foreach ($questions as $question) {
            foreach ($locales as $locale) {
                for ($run = 1; $run <= $runs; $run++) {
                    if ($spent() >= $budget) {
                        $this->warn(sprintf('Budget reached ($%.4f); stopping.', $spent()));

                        return self::SUCCESS;
                    }

                    $before = $spent();
                    $trace = $this->diagnose($search, $question, $locale);
                    $trace['cost'] = round($spent() - $before, 6);
                    $suffix = $runs > 1 ? "_run{$run}" : '';
                    file_put_contents("{$out}/q{$question->id}_{$locale}{$suffix}.json", json_encode($trace, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
                    $this->line(sprintf('#%d %s run %d: %s ($%.4f)', $question->id, $locale, $run, $trace['status'], $trace['cost']));
                }
            }
        }

        $this->info(sprintf('Spent $%.4f.', $spent()));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnose(FatwaSearch $search, CommonQuestion $question, string $locale): array
    {
        $text = (string) $question->questionFor($locale);
        $trace = ['question_id' => $question->id, 'locale' => $locale, 'question' => $text, 'events' => []];

        if ($text === '') {
            return [...$trace, 'status' => 'skipped'];
        }

        try {
            $results = $search->search($text, $locale);
        } catch (SearchUnavailable) {
            return [...$trace, 'status' => 'unavailable'];
        }

        $publicIds = Fatwa::query()->whereKey(array_column($results, 'fatwaId'))->pluck('public_id', 'id');
        $fatwaIds = array_values(array_filter(array_map(fn (array $result): ?string => $publicIds[$result['fatwaId']] ?? null, $results)));
        $order = array_flip($fatwaIds);
        $fatwas = Fatwa::query()->whereIn('public_id', $fatwaIds)->get()->sortBy(fn (Fatwa $fatwa): int => $order[$fatwa->public_id])->values();
        $trace['fatwa_ids'] = $fatwaIds;

        $events = [];
        $result = app(SummaryWriter::class)
            ->forClient('common-questions-diagnosis')
            ->tracing(function (string $event, array $data) use (&$events): void {
                $events[] = ['event' => $event, ...$data];
            })
            ->write($text, $fatwas, $locale);

        $trace['events'] = $events;
        $trace['status'] = $result['status'];
        $trace['summary'] = $result['summary'] ?? null;
        $trace['shadow'] = $this->option('no-shadow-judge') ? [] : $this->shadowJudge($events);

        return $trace;
    }

    /**
     * The claims judge's verdicts on the items the safeguard rejected, one call per draft.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array<int, array<string, mixed>> verdicts by attempt
     */
    private function shadowJudge(array $events): array
    {
        $byAttempt = [];
        $attempt = 0;

        foreach ($events as $event) {
            if ($event['event'] === 'attempt') {
                $attempt = $event['attempt'];
            }

            if ($event['event'] === 'item' && $event['checked'] === null && ($event['claim']['quotes'] ?? []) !== []) {
                $byAttempt[$attempt][$event['key']] = $event['claim'];
            }
        }

        $verdicts = [];

        foreach ($byAttempt as $attempt => $claims) {
            try {
                $verdicts[$attempt] = app(EntailmentJudge::class)->claims($claims);
            } catch (Throwable $exception) {
                $verdicts[$attempt] = ['error' => $exception::class];
            }
        }

        return $verdicts;
    }
}
