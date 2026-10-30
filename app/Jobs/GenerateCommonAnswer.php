<?php

namespace App\Jobs;

use App\Models\CommonQuestion;
use App\Services\CommonAnswerGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Regenerates one common question's answers from the admin (queued: a summary takes up to a few minutes).
 */
class GenerateCommonAnswer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @param  list<string>  $locales
     */
    public function __construct(public int $questionId, public array $locales = ['ar', 'en']) {}

    public function handle(CommonAnswerGenerator $generator): void
    {
        $question = CommonQuestion::query()->find($this->questionId);

        if ($question === null) {
            return;
        }

        foreach ($this->locales as $locale) {
            if ($generator->generate($question, $locale) === CommonAnswerGenerator::PAUSED) {
                return;
            }
        }
    }
}
