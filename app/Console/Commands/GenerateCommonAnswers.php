<?php

namespace App\Console\Commands;

use App\Models\CommonAnswer;
use App\Models\CommonQuestion;
use App\Services\CommonAnswerGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class GenerateCommonAnswers extends Command
{
    protected $signature = 'common:generate
        {--locale= : Only this locale (ar or en); both by default}
        {--only= : Only these common question ids, comma-separated; their stored answers are regenerated}
        {--force : Regenerate every stored answer too}';

    protected $description = 'Generate the answers to the home page common questions that have none yet: the real search plus the summary (costs AI money). Stored answers are kept for good unless --only or --force says otherwise.';

    public function handle(CommonAnswerGenerator $generator): int
    {
        $locales = match ($this->option('locale')) {
            null, '' => ['ar', 'en'],
            'ar', 'en' => [(string) $this->option('locale')],
            default => null,
        };

        if ($locales === null) {
            $this->error('--locale must be ar or en.');

            return self::INVALID;
        }

        // Two runs at once would pay for the same summaries twice.
        $lock = Cache::lock('common:generate', 3 * 3600);

        if (! $lock->get()) {
            $this->warn('Another common:generate run is active; not starting a second one.');

            return self::SUCCESS;
        }

        try {
            return $this->generateAll($generator, $locales);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<string>  $locales
     */
    private function generateAll(CommonAnswerGenerator $generator, array $locales): int
    {
        $only = array_filter(array_map('intval', explode(',', (string) $this->option('only'))));

        $questions = CommonQuestion::query()
            ->active()
            ->when($only !== [], fn ($query) => $query->whereKey($only))
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($questions->isEmpty()) {
            $this->warn('No active common questions to generate.');

            return self::SUCCESS;
        }

        // One alert for the whole run, not one per failed question and locale.
        $generator->startRun();

        try {
            // Fatwas never change, so a stored answer stays as it is unless it is asked for by id or with --force.
            return $this->generateQuestions($generator, $questions, $locales, regenerate: $only !== [] || (bool) $this->option('force'));
        } finally {
            $generator->finishRun(everyQuestion: $only === [] && count($locales) === 2);
        }
    }

    /**
     * @param  iterable<CommonQuestion>  $questions
     * @param  list<string>  $locales
     */
    private function generateQuestions(CommonAnswerGenerator $generator, iterable $questions, array $locales, bool $regenerate): int
    {
        $failures = 0;

        foreach ($questions as $question) {
            foreach ($locales as $locale) {
                if (! $regenerate && CommonAnswer::query()->where('common_question_id', $question->id)->where('locale', $locale)->exists()) {
                    $this->line("#{$question->id} {$locale}: kept (already generated)");

                    continue;
                }

                try {
                    $outcome = $generator->generate($question, $locale);
                } catch (Throwable $exception) {
                    $generator->crashed($question, $locale, $exception);

                    throw $exception;
                }

                $this->line("#{$question->id} {$locale}: {$outcome}");

                if ($outcome === CommonAnswerGenerator::PAUSED) {
                    $this->warn('The monthly AI budget is spent; stopping. Stored answers are kept.');

                    return self::FAILURE;
                }

                if (in_array($outcome, [CommonAnswerGenerator::FAILED, CommonAnswerGenerator::UNAVAILABLE], true)) {
                    $failures++;
                }
            }
        }

        $this->info($failures === 0 ? 'Every common answer was generated.' : "{$failures} common answer(s) failed; see the alerts.");

        return self::SUCCESS;
    }
}
