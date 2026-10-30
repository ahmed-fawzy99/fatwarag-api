<?php

namespace App\Services;

use App\Answers\AnswerStore;
use App\Models\CommonAnswer;
use App\Models\Fatwa;
use App\Support\Arabic;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Serving the pre-generated answers to the home page's common questions. A visitor's question that matches an active
 * common question exactly (after normalizing) gets its stored fatwa list at once, through the usual answer handoff,
 * flagged so both summary endpoints return the stored summary instead of writing one. Visitor questions are never stored.
 */
class CommonAnswers
{
    public function __construct(private AnswerStore $answers) {}

    /**
     * The form a question is matched in: Arabic through `Arabic::normalize`; English lowercased, whitespace squished and
     * trailing «?.!» dropped.
     */
    public static function normalize(?string $text, string $locale): string
    {
        if ($locale !== 'en') {
            return Arabic::normalize($text);
        }

        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $text)));

        return rtrim($text, ' ?.!؟');
    }

    /**
     * The fresh stored answer for an active common question this question matches exactly, or null (search it live).
     */
    public function find(string $question, string $locale): ?CommonAnswer
    {
        $normalized = self::normalize($question, $locale);

        if ($normalized === '') {
            return null;
        }

        try {
            $answer = CommonAnswer::query()
                ->where('locale', $locale)
                ->whereHas('question', fn ($query) => $query->active()->where($locale === 'en' ? 'normalized_en' : 'normalized_ar', $normalized))
                ->first();
        } catch (Throwable $exception) {
            Log::warning('Common answer lookup failed', ['error' => $exception::class]);

            return null;
        }

        return $answer !== null && $answer->isGenerated() && $answer->fatwa_ids !== [] ? $answer : null;
    }

    /**
     * Stores the answer in the short-lived handoff, flagged as cached.
     *
     * @return array{answerId: string, fatwaIds: list<string>}|null null when none of its fatwas exist any more
     */
    public function handOff(CommonAnswer $answer, string $question): ?array
    {
        $existing = array_flip(Fatwa::query()->whereIn('public_id', $answer->fatwa_ids)->pluck('public_id')->all());
        $fatwaIds = array_values(array_filter($answer->fatwa_ids, fn (string $publicId): bool => isset($existing[$publicId])));

        if ($fatwaIds === []) {
            return null;
        }

        $answerId = $this->answers->put($question, $answer->locale, $fatwaIds);
        $this->store()->put($this->flagKey($answerId), [
            'commonAnswerId' => $answer->id,
            'generatedAt' => $answer->generated_at->toIso8601String(),
        ], AnswerStore::TTL_SECONDS);

        return ['answerId' => $answerId, 'fatwaIds' => $fatwaIds];
    }

    /**
     * The stored summary for a cached answer: `ok` with the summary, or `failed` (the fatwa list alone). Null when the
     * answer isn't a cached one, or can't be served as stored (another locale asked for, or regenerated since the
     * search): the summary is then written as usual.
     *
     * @return array{status: 'ok'|'failed', summary?: array<string, mixed>}|null
     */
    public function cachedSummary(string $answerId, string $locale): ?array
    {
        try {
            $flag = $this->store()->get($this->flagKey($answerId));

            if (! is_array($flag)) {
                return null;
            }

            $answer = CommonAnswer::query()->find($flag['commonAnswerId'] ?? null);
        } catch (Throwable $exception) {
            Log::warning('Cached common summary lookup failed', ['error' => $exception::class]);

            return null;
        }

        if ($answer === null || $answer->locale !== $locale || $answer->generated_at->toIso8601String() !== ($flag['generatedAt'] ?? null)) {
            return null;
        }

        if ($answer->status !== CommonAnswer::STATUS_OK || ! is_array($answer->summary)) {
            return ['status' => 'failed'];
        }

        return ['status' => 'ok', 'summary' => $answer->summary + ['extraFatwaIds' => []]];
    }

    private function flagKey(string $answerId): string
    {
        return "ask:common:{$answerId}";
    }

    private function store(): Repository
    {
        return Cache::store(config('fatwarag.ask.cache_store'));
    }
}
