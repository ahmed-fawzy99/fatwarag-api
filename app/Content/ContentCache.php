<?php

namespace App\Content;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Redis cache (with tags) for content responses, one day by default. Model observers clear the tags when content changes
 * (see ContentCacheServiceProvider). Summaries are never cached, and a cache that is down or has no tag support simply
 * means every request is computed: the cache is an optimisation, never a dependency.
 *
 * Tags:
 *   fatwa:{publicId}   one fatwa, in every locale
 *   fatwa-payloads     every cached single fatwa (cleared after bulk changes such as topic assignment)
 *   fatwa-lists        anything that lists fatwas: topic lists, topic counts, related fatwas, the sitemap
 *   topics             the topic tree and topic lists
 *   surah:{number}     a surah and its tafsir passages
 *   hadith:{id}        one hadith
 *   catalog            sources, muftis, glossary
 *   common-questions   the home page common questions
 */
class ContentCache
{
    /** The tag every cached single-fatwa payload carries. */
    public const PAYLOADS = 'fatwa-payloads';

    private const PREFIX = 'content:';

    private const QUOTE_INDEX_PREFIX = 'content-quote-index:';

    /**
     * Returns the cached value, or computes it with $callback and stores it.
     *
     * @template T
     *
     * @param  array<int, string>  $tags
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function remember(array $tags, string $key, Closure $callback, ?int $ttl = null): mixed
    {
        $hit = self::get($tags, $key);

        if ($hit !== null) {
            return $hit;
        }

        $value = $callback();
        self::put($tags, $key, $value, $ttl);

        return $value;
    }

    /**
     * @param  array<int, string>  $tags
     */
    public static function get(array $tags, string $key): mixed
    {
        try {
            return self::tagged($tags)?->get(self::PREFIX.$key);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $tags
     */
    public static function put(array $tags, string $key, mixed $value, ?int $ttl = null): void
    {
        if ($value === null) {
            return;
        }

        try {
            self::tagged($tags)?->put(self::PREFIX.$key, $value, $ttl ?? (int) config('fatwarag.content.cache_ttl', 86400));
        } catch (Throwable) {
            // Not cached this time; the next request computes it again.
        }
    }

    /**
     * @param  array<int, string>  $tags
     */
    public static function forget(array $tags): void
    {
        try {
            self::tagged($tags)?->flush();
        } catch (Throwable) {
            // Nothing cached means nothing stale.
        }
    }

    public static function forgetFatwa(string $publicId): void
    {
        self::forget(["fatwa:{$publicId}"]);
    }

    /** Everything derived from topic assignment or other bulk changes: lists, the topic tree and every cached fatwa payload. */
    public static function forgetDerived(): void
    {
        self::forget(['fatwa-lists']);
        self::forget(['topics']);
        self::forget(['hadith-lists']);
        self::forget([self::PAYLOADS]);
    }

    public static function forgetLists(): void
    {
        self::forget(['fatwa-lists']);
    }

    /**
     * Remembers which cached fatwas contain a hadith quote, so finding the hadith can clear exactly those.
     *
     * @param  array<int, string>  $quoteKeys
     */
    public static function indexQuotes(string $publicId, array $quoteKeys): void
    {
        try {
            $store = self::store();

            foreach (array_unique($quoteKeys) as $quoteKey) {
                $ids = $store->get(self::QUOTE_INDEX_PREFIX.$quoteKey, []);

                if (! in_array($publicId, $ids, true)) {
                    $store->put(self::QUOTE_INDEX_PREFIX.$quoteKey, [...$ids, $publicId], (int) config('fatwarag.content.cache_ttl', 86400));
                }
            }
        } catch (Throwable) {
            // Without the index a quote may stay unlinked in a cached fatwa until its day is up.
        }
    }

    /** A hadith lookup finished: clear the cached fatwas that quote it. */
    public static function forgetFatwasQuoting(string $quoteKey): void
    {
        try {
            $store = self::store();
            $ids = $store->get(self::QUOTE_INDEX_PREFIX.$quoteKey, []);
            $store->forget(self::QUOTE_INDEX_PREFIX.$quoteKey);
        } catch (Throwable) {
            return;
        }

        foreach ($ids as $publicId) {
            self::forgetFatwa($publicId);
        }
    }

    private static function store(): Repository
    {
        return Cache::store(config('fatwarag.content.cache_store'));
    }

    /**
     * @param  array<int, string>  $tags
     */
    private static function tagged(array $tags): ?Repository
    {
        $store = self::store();

        return $store->supportsTags() ? $store->tags($tags) : null;
    }
}
