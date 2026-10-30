<?php

namespace Tests\Feature\Content;

use App\Content\HadithQuoteRegistry;
use App\Models\HadithQuote;
use App\Support\Arabic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HadithQuoteRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_with_and_without_harakat_maps_to_same_key(): void
    {
        $this->assertSame(Arabic::quoteKey('إِنَّمَا الأَعْمَالُ'), Arabic::quoteKey('إنما الأعمال'));

        $registry = app(HadithQuoteRegistry::class);
        $first = $registry->register('إِنَّمَا الأَعْمَالُ بِالنِّيَّاتِ');
        $second = $registry->register('«إنما الأعمال بالنيات»');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('hadith_quotes', 1);
        $this->assertSame(2, HadithQuote::query()->where('quote_key', $first)->value('fatwa_count'));
    }

    public function test_new_quote_is_pending_and_keeps_its_text(): void
    {
        $key = app(HadithQuoteRegistry::class)->register('«من صام رمضان إيمانا واحتسابا»');

        $quote = HadithQuote::query()->where('quote_key', $key)->sole();
        $this->assertSame('pending', $quote->status);
        $this->assertSame('من صام رمضان إيمانا واحتسابا', $quote->quote);
        $this->assertSame(1, $quote->fatwa_count);
    }

    public function test_release_decrements_without_going_negative(): void
    {
        $registry = app(HadithQuoteRegistry::class);
        $key = $registry->register('من صام رمضان إيمانا واحتسابا');

        $registry->release($key);
        $registry->release($key);

        $this->assertSame(0, HadithQuote::query()->where('quote_key', $key)->value('fatwa_count'));
    }
}
