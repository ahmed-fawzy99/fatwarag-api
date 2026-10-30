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
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SummaryStreamController extends Controller
{
    /** Longer than the worst case: the rewrite budget, then the model's timeout and the step processes' timeout. */
    public const MAX_SECONDS = 360;

    private const HEADERS = [
        'Content-Type' => 'text/event-stream; charset=UTF-8',
        'Cache-Control' => 'no-cache, no-transform',
        'X-Accel-Buffering' => 'no',
    ];

    /**
     * GET /answers/{answerId}/summary/stream: the same summary as the JSON endpoint, as server-sent events:
     * `stage` as each phase starts, `item` for each part once it has passed every check (in display
     * order), then `done` with the final Summary, or a terminal `status` (`failed` / `paused`). Any error is `failed`.
     * The same answer cap, budget and identity rules apply; an unknown answer or a spent cap answers in JSON first.
     * A common question's answer sends its stored summary as `done` (or `status: failed`) at once, with no stages, and so
     * does an answer whose summary in that locale has already been written: only a failed attempt is tried again.
     * One visitor may have only so many summaries running at once (`fatwarag.summary.max_streams_per_client`).
     */
    public function __invoke(Request $request, string $answerId, AnswerStore $answers, SummaryWriter $writer, SummaryThrottle $throttle, CommonAnswers $common, AnswerShareMemory $shareMemory, QuestionGate $gate, SummaryLimiter $limiter, SummaryStreamLimiter $running): JsonResponse|StreamedResponse
    {
        $entry = $answers->get($answerId);

        if ($entry === null) {
            return ErrorResponse::notFound('This answer has expired. Please ask again.');
        }

        $locale = in_array($request->query('locale'), ['ar', 'en'], true) ? $request->query('locale') : $entry['locale'];

        if ($cached = $common->cachedSummary($answerId, $locale)) {
            if ($cached['status'] === 'ok') {
                $answers->addFatwaIds($answerId, $cached['summary']['extraFatwaIds']);
            }

            return $this->cached($cached);
        }

        if ($gate->cached($entry['question']) === false) {
            // Judged off-topic after this answer was made (a verdict that arrived late): no summary is written.
            return ErrorResponse::make('off_topic', 'This site only answers religious questions.', 422);
        }

        if ($stored = $shareMemory->summary($answerId, $locale)) {
            // Already written for this answer and locale: the same summary again, with no new AI call.
            return $this->cached(['status' => 'ok', 'summary' => $stored]);
        }

        $ranked = (bool) ($entry['ranked'] ?? true);
        $client = SummaryThrottle::client($request);

        if ($ranked) {
            // Taken before the other checks and given back if one refuses, or once the stream has ended.
            if (! $running->acquire($client)) {
                return ErrorResponse::make('rate_limited', 'Too many summaries at once. Please wait for one to finish.', 429);
            }

            $refusal = match (true) {
                ! $throttle->allows($request) => 'Too many summaries. Please wait a minute.',
                ! $answers->claimSummary($answerId) => 'This answer has already been summarised.',
                ! $limiter->attempt($client) => 'Too many summaries this hour. Please try again later.',
                default => null,
            };

            if ($refusal !== null) {
                $running->release($client);

                return ErrorResponse::make('rate_limited', $refusal, 429);
            }
        }

        return response()->stream(function () use ($entry, $answerId, $ranked, $locale, $answers, $writer, $client, $shareMemory, $running): void {
            // The worst case: a first draft that fails just inside the rewrite budget, then a rewrite that runs to the
            // model's timeout and its checks to the step processes' timeout. On Linux this limit counts CPU time, not
            // wall time, so the writer also stops at its own wall-clock deadline (`fatwarag.summary.wall_seconds`).
            // Under Octane the worker serves later requests, so its own limit is put back when the stream ends.
            $timeLimit = (int) ini_get('max_execution_time');
            set_time_limit(self::MAX_SECONDS);
            ignore_user_abort(true);

            $terminal = false;
            $lastWrite = microtime(true);
            $write = function (string $text) use (&$lastWrite): void {
                echo $text;

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
                $lastWrite = microtime(true);
            };
            $send = function (string $event, array $data) use ($write, &$terminal): void {
                if ($terminal) {
                    return;
                }

                $write("event: {$event}\ndata: ".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n\n");
                $terminal = in_array($event, ['done', 'status'], true);
            };
            // Asked often while the summary runs: a heartbeat comment every few seconds makes a reader who left show
            // up as an aborted connection, and then everything stops.
            $heartbeat = (float) config('fatwarag.summary.heartbeat_seconds', 5);
            $alive = function () use ($write, &$lastWrite, $heartbeat): bool {
                if (microtime(true) - $lastWrite >= $heartbeat) {
                    $write(": ping\n\n");
                }

                return connection_aborted() === 0;
            };
            // Whatever happens (an error, the time limit), the stream ends with a terminal event.
            register_shutdown_function(function () use ($send, &$terminal): void {
                if (! $terminal) {
                    $send('status', ['status' => 'failed']);
                }
            });

            try {
                if (! $ranked) {
                    // The re-ranker failed, so these fatwas weren't checked for relevance: the list alone is the safe state.
                    $send('status', ['status' => 'failed']);

                    return;
                }

                $order = array_flip($entry['fatwaIds']);
                $fatwas = Fatwa::query()->whereIn('public_id', $entry['fatwaIds'])->get()
                    ->sortBy(fn (Fatwa $fatwa): int => $order[$fatwa->public_id])
                    ->values();

                $result = $writer->forClient($client)->forParts($entry['parts'] ?? [])->write($entry['question'], $fatwas, $locale, $send, $alive);

                if ($result['status'] !== 'ok') {
                    $send('status', ['status' => $result['status']]);

                    return;
                }

                $answers->addFatwaIds($answerId, $result['summary']['extraFatwaIds']);
                // Kept for the hour so the visitor can share it; saved only if they do.
                $shareMemory->rememberSummary($answerId, $locale, $result['summary']);
                $send('done', $result['summary']);
            } catch (Throwable $exception) {
                // Fail closed, and never log the question: only the error's class.
                Log::warning('Summary stream failed', ['error' => $exception::class]);
            } finally {
                if (! $terminal) {
                    $send('status', ['status' => 'failed']);
                }

                set_time_limit($timeLimit);

                if ($ranked) {
                    $running->release($client);
                }
            }
        }, 200, self::HEADERS);
    }

    /**
     * @param  array{status: string, summary?: array<string, mixed>}  $cached
     */
    private function cached(array $cached): StreamedResponse
    {
        [$event, $data] = $cached['status'] === 'ok' ? ['done', $cached['summary']] : ['status', ['status' => $cached['status']]];

        return response()->stream(function () use ($event, $data): void {
            echo "event: {$event}\ndata: ".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n\n";

            if (ob_get_level() > 0) {
                ob_flush();
            }

            flush();
        }, 200, self::HEADERS);
    }
}
