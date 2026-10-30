<?php

namespace App\Http\Controllers\Api;

use App\Answers\AnswerStore;
use App\Answers\FollowUpMerger;
use App\Answers\QuestionGate;
use App\Answers\QuestionParts;
use App\Answers\SummaryThrottle;
use App\Content\ErrorResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequest;
use App\Limits\QuestionLimiter;
use App\Models\Fatwa;
use App\Models\Source;
use App\Search\FatwaSearch;
use App\Search\SearchUnavailable;
use App\Services\CommonAnswers;
use App\Shares\AnswerShareMemory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class SearchController extends Controller
{
    /**
     * POST /search : limits (daily and per visitor, the abuse guard here; no Turnstile), an optional follow-up merge, then the two-pass search. The question is never
     * stored or logged; only the short-lived answer handoff keeps it, for the summary. A question that matches one of the
     * home page's common questions exactly is answered at once from its stored answer (it still counts against the limits).
     * A question that is clearly not religious (QuestionGate, checked beside the search) gets `off_topic` and no fatwas.
     */
    public function __invoke(
        SearchRequest $request,
        QuestionLimiter $limiter,
        FollowUpMerger $merger,
        FatwaSearch $search,
        AnswerStore $answers,
        CommonAnswers $common,
        AnswerShareMemory $shareMemory,
        QuestionParts $questionParts,
        QuestionGate $gate,
    ): JsonResponse {
        $ip = (string) $request->attributes->get('client_ip', $request->ip());

        if ($refusal = $limiter->attempt($ip)) {
            return ErrorResponse::make($refusal, $refusal === QuestionLimiter::DAILY_CAP_REACHED
                ? 'The daily number of questions has been reached. Please try again tomorrow.'
                : 'Too many questions in a short time. Please try again later.', 429);
        }

        $question = $request->question();
        $original = $request->originalQuestion();
        $mergedFrom = null;

        if ($original === null && ($stored = $common->find($question, $request->locale())) && ($handoff = $common->handOff($stored, $question))) {
            return response()->json([
                'answerId' => $handoff['answerId'],
                'question' => $question,
                'mergedFrom' => null,
                'sourcesSearched' => $this->sourcesSearched(),
                'fatwaIds' => $handoff['fatwaIds'],
            ]);
        }

        if ($original !== null) {
            $mergedFrom = ['original' => $original, 'followUp' => $question];
            $question = $merger->merge($original, $question);
        }

        // A question with no real words (a repeated letter, keyboard mashing, only symbols or digits) is never searched.
        if (QuestionGate::meaningless($question)) {
            return ErrorResponse::make('off_topic', 'This site only answers religious questions.', 422);
        }

        // Whether the question is religious is decided beside the search, on the whole question (so it adds no delay).
        $gateCheck = $gate->begin($question, SummaryThrottle::client($request));

        // A question asking several things is searched part by part (a cheap wording check first, so most questions
        // make no extra call).
        $detected = $questionParts->detect($question);

        try {
            $searches = $detected === [] ? [$search->search($question, $request->locale())] : $this->searchParts($search, $detected, $request->locale());
        } catch (SearchUnavailable) {
            return ErrorResponse::make('upstream_failed', 'Search is unavailable right now. Please try again shortly.', 502);
        }

        if (! $gateCheck->allowed()) {
            return ErrorResponse::make('off_topic', 'This site only answers religious questions.', 422);
        }

        $results = array_merge(...$searches);

        if ($results === []) {
            return ErrorResponse::notFound('Nothing in the sources answers this question.');
        }

        $publicIds = Fatwa::query()->whereKey(array_column($results, 'fatwaId'))->pluck('public_id', 'id')->all();
        $lists = array_map(fn (array $found): array => array_values(array_filter(array_map(fn (array $result): ?string => $publicIds[$result['fatwaId']] ?? null, $found))), $searches);
        $fatwaIds = $detected === [] ? $lists[0] : $this->mergeParts($lists, (int) config('fatwarag.search.max_results_parts', 10));
        $parts = $detected === [] ? [] : array_map(fn (array $part, array $ids): array => [
            'label' => $part['label'],
            'fatwaIds' => array_values(array_intersect($ids, $fatwaIds)),
        ], $detected, $lists);

        $answerId = $answers->put($question, $request->locale(), $fatwaIds, ranked: ! in_array(false, array_column($results, 'ranked'), true), parts: $parts);

        if ($mergedFrom !== null) {
            // Kept beside the handoff for the hour, so a share can say which follow-up the question came from.
            $shareMemory->rememberFollowUp($answerId, $mergedFrom['followUp']);
        }

        return response()->json([
            'answerId' => $answerId,
            'question' => $question,
            'mergedFrom' => $mergedFrom,
            'sourcesSearched' => $this->sourcesSearched(),
            'fatwaIds' => $fatwaIds,
            ...($parts === [] ? [] : ['parts' => $parts]),
        ]);
    }

    /**
     * Each part searched on its own. A part whose search fails has no results of its own and the others keep theirs;
     * only when every part's search fails is search unavailable.
     *
     * @param  list<array{label: string, query: string}>  $parts
     * @return list<list<array{fatwaId: int, score: float, ranked: bool}>>
     *
     * @throws SearchUnavailable
     */
    private function searchParts(FatwaSearch $search, array $parts, string $locale): array
    {
        $searches = [];
        $failed = 0;

        foreach ($parts as $part) {
            try {
                $searches[] = $search->search($part['query'], $locale, (int) config('fatwarag.search.max_results_per_part', 5));
            } catch (SearchUnavailable) {
                $searches[] = [];
                $failed++;
            }
        }

        if ($failed === count($parts)) {
            throw new SearchUnavailable('No part of the question could be searched.');
        }

        if ($failed > 0) {
            // Never the question: only how many parts could not be searched.
            Log::warning('Search of a question part failed; the other parts keep their results', ['parts' => count($parts), 'failed' => $failed]);
        }

        return $searches;
    }

    /**
     * Each part's results merged best-first: every part's best hit, then every part's second, and so on, each fatwa
     * once, at most $cap in all, so each part keeps its best hits.
     *
     * @param  list<list<string>>  $lists
     * @return list<string>
     */
    private function mergeParts(array $lists, int $cap): array
    {
        $merged = [];

        for ($rank = 0; $rank < max(array_map(count(...), $lists)); $rank++) {
            foreach ($lists as $list) {
                if (isset($list[$rank]) && ! in_array($list[$rank], $merged, true)) {
                    $merged[] = $list[$rank];
                }
            }
        }

        return array_slice($merged, 0, max(1, $cap));
    }

    private function sourcesSearched(): int
    {
        return Source::query()->where('kind', 'fatwa')->where('is_enabled', true)->count();
    }
}
