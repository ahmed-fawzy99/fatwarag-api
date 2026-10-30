<?php

namespace App\Http\Controllers\Api;

use App\Answers\AnswerStore;
use App\Content\ErrorResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShareRequest;
use App\Limits\ShareLimiter;
use App\Models\SharedAnswer;
use App\Services\CommonAnswers;
use App\Services\Turnstile;
use App\Shares\AnswerShareMemory;
use Illuminate\Http\JsonResponse;

class ShareController extends Controller
{
    /**
     * POST /shares : saves the answer the visitor chose to share (they agreed to it being stored): the handoff's
     * question and fatwa list, the follow-up it came from, and the final summary in that locale if one was written
     * (a share after a failed summary keeps the list only). Behind Turnstile and a per-visitor hourly limit.
     */
    public function store(StoreShareRequest $request, Turnstile $turnstile, ShareLimiter $limiter, AnswerStore $answers, AnswerShareMemory $memory, CommonAnswers $common): JsonResponse
    {
        $ip = (string) $request->attributes->get('client_ip', $request->ip());

        if (! $turnstile->verify($request->validated('turnstileToken'), $ip)) {
            return ErrorResponse::make('turnstile_failed', 'The security check failed. Please try again.', 403);
        }

        $ipHash = ShareLimiter::ipHash($ip);

        if ($limiter->tooMany($ipHash)) {
            return ErrorResponse::make('rate_limited', 'Too many shares in a short time. Please try again later.', 429);
        }

        $answerId = (string) $request->validated('answerId');
        $locale = (string) $request->validated('locale');
        $entry = $answers->get($answerId);

        if ($entry === null) {
            return ErrorResponse::notFound('This answer has expired. Please ask again.');
        }

        $cached = $common->cachedSummary($answerId, $locale);
        $summary = $cached !== null
            ? ($cached['status'] === 'ok' ? $cached['summary'] : null)
            : $memory->summary($answerId, $locale);

        $share = SharedAnswer::query()->create([
            'public_id' => SharedAnswer::newPublicId(),
            'question' => $entry['question'],
            'follow_up' => $memory->followUp($answerId),
            'locale' => $locale,
            'fatwa_ids' => array_values($entry['fatwaIds']),
            'summary' => $summary,
            'ip_hash' => $ipHash,
        ]);

        $limiter->hit($ipHash);

        return response()->json(['id' => $share->public_id, 'url' => self::url($share)], 201);
    }

    /** GET /shares/{id} : a shared answer, exactly as it was saved. */
    public function show(string $id): JsonResponse
    {
        $share = preg_match('/^[A-Za-z0-9]{10,32}$/', $id) ? SharedAnswer::query()->where('public_id', $id)->first() : null;

        if ($share === null) {
            return ErrorResponse::notFound('This shared answer does not exist.');
        }

        return response()->json([
            'id' => $share->public_id,
            'question' => $share->question,
            'followUp' => $share->follow_up,
            'locale' => $share->locale,
            'fatwaIds' => $share->fatwa_ids,
            'summary' => $share->summary,
            'createdAt' => $share->created_at?->toIso8601String(),
        ]);
    }

    /** The share's page on the site, in the locale it was shared in. */
    public static function url(SharedAnswer $share): string
    {
        $base = rtrim((string) config('fatwarag.site_url'), '/');

        return $base.($share->locale === 'en' ? '/en' : '').'/s/'.$share->public_id;
    }
}
