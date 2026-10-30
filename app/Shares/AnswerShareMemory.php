<?php

namespace App\Shares;

use App\Answers\AnswerStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a share needs beyond the answer handoff, kept beside it in Redis for the same hour: the follow-up a merged
 * question came from, and each locale's final summary once it is written. Nothing here is persisted; only an explicit
 * share (POST /shares) copies it into `shared_answers`. Any cache failure just means a share carries less.
 */
class AnswerShareMemory
{
    public function rememberFollowUp(string $answerId, string $followUp): void
    {
        $this->put("{$answerId}:follow-up", $followUp);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function rememberSummary(string $answerId, string $locale, array $summary): void
    {
        $this->put("{$answerId}:summary:{$locale}", $summary);
    }

    public function followUp(string $answerId): ?string
    {
        $value = $this->get("{$answerId}:follow-up");

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function summary(string $answerId, string $locale): ?array
    {
        $value = $this->get("{$answerId}:summary:{$locale}");

        return is_array($value) ? $value : null;
    }

    private function put(string $key, mixed $value): void
    {
        try {
            $this->store()->put("ask:share:{$key}", $value, AnswerStore::TTL_SECONDS);
        } catch (Throwable $exception) {
            Log::warning('Could not keep an answer for sharing', ['error' => $exception::class]);
        }
    }

    private function get(string $key): mixed
    {
        try {
            return $this->store()->get("ask:share:{$key}");
        } catch (Throwable $exception) {
            Log::warning('Could not read an answer kept for sharing', ['error' => $exception::class]);

            return null;
        }
    }

    private function store(): Repository
    {
        return Cache::store(config('fatwarag.ask.cache_store'));
    }
}
