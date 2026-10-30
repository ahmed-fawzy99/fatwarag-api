<?php

namespace App\Answers;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The short-lived handoff between search and summary: an opaque `answerId` pointing to a Redis entry
 * holding only the question, locale and fatwa ids (and, for a question in parts, each part's label and fatwa ids), kept
 * for an hour. It is never logged or persisted.
 */
class AnswerStore
{
    public const TTL_SECONDS = 3600;

    /** How many summaries one answer may produce (a retry after a failure, not a way to spend the budget). */
    public const MAX_SUMMARIES = 3;

    /**
     * @param  list<string>  $fatwaIds  public ids, best first
     * @param  bool  $ranked  false when the re-ranker failed: the list is unranked, and no summary is written for it
     * @param  list<array{label: string, fatwaIds: list<string>}>  $parts  a question in parts: each part's own results
     */
    public function put(string $question, string $locale, array $fatwaIds, bool $ranked = true, array $parts = []): string
    {
        $answerId = bin2hex(random_bytes(16));
        $entry = ['question' => $question, 'locale' => $locale, 'fatwaIds' => $fatwaIds, 'ranked' => $ranked];

        $this->store()->put($this->key($answerId), $parts === [] ? $entry : [...$entry, 'parts' => $parts], self::TTL_SECONDS);

        return $answerId;
    }

    /**
     * Adds fatwas the summary cited (cross-references) to the answer, so their citation links resolve.
     *
     * @param  list<string>  $fatwaIds
     */
    public function addFatwaIds(string $answerId, array $fatwaIds): void
    {
        $entry = $this->get($answerId);

        if ($entry === null || $fatwaIds === []) {
            return;
        }

        $entry['fatwaIds'] = array_values(array_unique([...$entry['fatwaIds'], ...$fatwaIds]));
        $this->store()->put($this->key($answerId), $entry, self::TTL_SECONDS);
    }

    /**
     * @return array{question: string, locale: string, fatwaIds: list<string>, ranked?: bool, parts?: list<array{label: string, fatwaIds: list<string>}>}|null
     */
    public function get(string $answerId): ?array
    {
        if (! preg_match('/^[0-9a-f]{32}$/', $answerId)) {
            return null;
        }

        $entry = $this->store()->get($this->key($answerId));

        return is_array($entry) ? $entry : null;
    }

    /**
     * Counts one summary for the answer; false once it has had MAX_SUMMARIES.
     */
    public function claimSummary(string $answerId): bool
    {
        $key = $this->key($answerId).':summaries';
        $store = $this->store();

        $store->add($key, 0, self::TTL_SECONDS);

        return (int) $store->increment($key) <= self::MAX_SUMMARIES;
    }

    private function key(string $answerId): string
    {
        return "ask:answer:{$answerId}";
    }

    private function store(): Repository
    {
        return Cache::store(config('fatwarag.ask.cache_store'));
    }
}
