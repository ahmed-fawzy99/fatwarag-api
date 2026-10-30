<?php

namespace App\Content;

use App\Models\HadithQuote;

/**
 * Turns stored rich paragraphs into the public Segment shape (web/src/types/content.ts).
 *
 * Stored hadith segments carry an internal `quoteKey`; the API replaces it with `ref`, resolved late through
 * `hadith_quotes`, so a Dorar lookup that finishes later links every fatwa that quotes the hadith.
 */
class SegmentPresenter
{
    /**
     * @param  array<string, array{collection: string, number: int}>  $refs  found quotes, by quote key
     */
    public function __construct(private array $refs = []) {}

    /**
     * Builds a presenter for every hadith quote found in the given paragraph lists, with one query.
     *
     * @param  iterable<array<int, array<int, array<string, mixed>>>|null>  $paragraphLists
     */
    public static function resolving(iterable $paragraphLists): self
    {
        $keys = [];

        foreach ($paragraphLists as $paragraphs) {
            foreach ($paragraphs ?? [] as $paragraph) {
                foreach ($paragraph as $segment) {
                    if (($segment['type'] ?? null) === 'hadith' && isset($segment['quoteKey'])) {
                        $keys[$segment['quoteKey']] = true;
                    }
                }
            }
        }

        if ($keys === []) {
            return new self;
        }

        $refs = [];

        HadithQuote::query()
            ->whereIn('quote_key', array_keys($keys))
            ->where('status', 'found')
            ->whereNotNull('hadith_id')
            ->get(['quote_key', 'hadith_id'])
            ->each(function (HadithQuote $quote) use (&$refs): void {
                $refs[$quote->quote_key] = ['collection' => 'dorar', 'number' => (int) $quote->hadith_id];
            });

        return new self($refs);
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>|null  $paragraphs
     * @return array<int, array<int, array<string, mixed>>>|null
     */
    public function paragraphs(?array $paragraphs): ?array
    {
        if ($paragraphs === null) {
            return null;
        }

        return array_map(
            fn (array $paragraph): array => array_map(fn (array $segment): array => $this->segment($segment), $paragraph),
            $paragraphs,
        );
    }

    /**
     * @param  array<string, mixed>  $segment
     * @return array<string, mixed>
     */
    public function segment(array $segment): array
    {
        if (($segment['type'] ?? null) !== 'hadith') {
            return $segment;
        }

        $key = $segment['quoteKey'] ?? null;
        unset($segment['quoteKey']);

        return $segment + ['ref' => $key !== null ? ($this->refs[$key] ?? null) : ($segment['ref'] ?? null)] + [
            'gradingInFatwa' => null,
            'gradingScope' => null,
            'gradingContext' => null,
        ];
    }
}
