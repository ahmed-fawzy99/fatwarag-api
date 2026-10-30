<?php

namespace App\Content;

use App\Models\HadithQuote;
use App\Support\Arabic;
use Illuminate\Support\Facades\DB;

/**
 * Keeps one `hadith_quotes` row per distinct hadith quote (keyed by its normalized text), counting how many fatwas
 * quote it. The Dorar lookup links the row later, so fatwa text never needs rewriting.
 */
class HadithQuoteRegistry
{
    /**
     * Records that one more fatwa quotes this text and returns its quote key.
     */
    public function register(string $quote): string
    {
        $key = Arabic::quoteKey($quote);
        $text = preg_replace('/^[\s«»"“”{}()]+|[\s«»"“”{}()]+$/u', '', $quote);

        DB::statement(
            'insert into hadith_quotes (quote_key, quote, status, fatwa_count, created_at, updated_at) values (?, ?, ?, 1, now(), now())
             on conflict (quote_key) do update set fatwa_count = hadith_quotes.fatwa_count + 1, updated_at = now()',
            [$key, $text, 'pending'],
        );

        return $key;
    }

    /**
     * Records that a fatwa no longer quotes this text (its source page changed).
     */
    public function release(string $quoteKey): void
    {
        HadithQuote::query()->where('quote_key', $quoteKey)->where('fatwa_count', '>', 0)->decrement('fatwa_count');
    }
}
