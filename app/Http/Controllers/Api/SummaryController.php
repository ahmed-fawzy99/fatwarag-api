<?php

namespace App\Http\Controllers\Api;

use App\Answers\AnswerStore;
use App\Answers\QuestionGate;
use App\Answers\SummaryThrottle;
use App\Answers\SummaryWriter;
use App\Content\ErrorResponse;
use App\Http\Controllers\Controller;
use App\Limits\SummaryLimiter;
use App\Limits\SummaryStreamLimiter;
use App\Models\Fatwa;
use App\Services\CommonAnswers;
use App\Shares\AnswerShareMemory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SummaryController extends Controller
{
    /**
     * GET /answers/{answerId}/summary : the grounded summary of a search's fatwas, `{status: "paused"}` when the monthly
     * budget is spent, or `{status: "failed"}` (the page then shows the fatwa list alone), or an `off_topic` error for a question the gate has judged not religious. Summaries are never cached.
     * `extraFatwaIds` lists cross-referenced fatwas the summary cites that aren't in the search result's list. A common
     * question's answer returns its stored summary at once (no AI call, so no summary cap applies), and so does an answer
     * whose summary in that locale has already been written: only a failed attempt is tried again.
     */
    public function __invoke(Request $request, string $answerId, AnswerStore $answers, SummaryWriter $writer, SummaryThrottle $throttle, CommonAnswers $common, AnswerShareMemory $shareMemory, QuestionGate $gate, SummaryLimiter $limiter, SummaryStreamLimiter $running): JsonResponse
    {
        $entry = $answers->get($answerId);

        if ($entry === null) {
            return ErrorResponse::notFound('This answer has expired. Please ask again.');
        }

        $locale = in_array($request->query('locale'), ['ar', 'en'], true) ? $request->query('locale') : $entry['locale'];

        if ($cached = $common->cachedSummary($answerId, $locale)) {
            if ($cached['status'] !== 'ok') {
                return response()->json(['status' => $cached['status']]);
            }

            $answers->addFatwaIds($answerId, $cached['summary']['extraFatwaIds']);

            return response()->json($cached['summary']);
        }

        if ($gate->cached($entry['question']) === false) {
            // Judged off-topic after this answer was made (a verdict that arrived late): no summary is written.
            return ErrorResponse::make('off_topic', 'This site only answers religious questions.', 422);
        }

        if ($stored = $shareMemory->summary($answerId, $locale)) {
            // Already written for this answer and locale: the same summary again, with no new AI call.
            return response()->json($stored);
        }

        if (! ($entry['ranked'] ?? true)) {
            // The re-ranker failed, so these fatwas weren't checked for relevance: the list alone is the safe state.
            return response()->json(['status' => 'failed']);
        }

        $client = SummaryThrottle::client($request);

        if (! $running->acquire($client)) {
            return ErrorResponse::make('rate_limited', 'Too many summaries at once. Please wait for one to finish.', 429);
        }

        try {
            return $this->write($request, $answerId, $entry, $locale, $client, $answers, $writer, $throttle, $shareMemory, $limiter);
        } finally {
            $running->release($client);
        }
    }

    /**
     * @param  array{question: string, locale: string, fatwaIds: list<string>, ranked?: bool, parts?: list<array{label: string, fatwaIds: list<string>}>}  $entry
     */
    private function write(Request $request, string $answerId, array $entry, string $locale, string $client, AnswerStore $answers, SummaryWriter $writer, SummaryThrottle $throttle, AnswerShareMemory $shareMemory, SummaryLimiter $limiter): JsonResponse
    {
        if (! $throttle->allows($request)) {
            return ErrorResponse::make('rate_limited', 'Too many summaries. Please wait a minute.', 429);
        }

        if (! $answers->claimSummary($answerId)) {
            return ErrorResponse::make('rate_limited', 'This answer has already been summarised.', 429);
        }

        if (! $limiter->attempt($client)) {
            return ErrorResponse::make('rate_limited', 'Too many summaries this hour. Please try again later.', 429);
        }

        $order = array_flip($entry['fatwaIds']);
        $fatwas = Fatwa::query()->whereIn('public_id', $entry['fatwaIds'])->get()
            ->sortBy(fn (Fatwa $fatwa): int => $order[$fatwa->public_id])
            ->values();

        $result = $writer->forClient($client)->forParts($entry['parts'] ?? [])->write($entry['question'], $fatwas, $locale);

        if ($result['status'] !== 'ok') {
            return response()->json(['status' => $result['status']]);
        }

        $answers->addFatwaIds($answerId, $result['summary']['extraFatwaIds']);
        // Kept for the hour so the visitor can share it; saved only if they do.
        $shareMemory->rememberSummary($answerId, $locale, $result['summary']);

        return response()->json($result['summary']);
    }
}
