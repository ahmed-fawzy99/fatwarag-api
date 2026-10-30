<?php

namespace Tests\Feature\Limits;

use App\Http\Middleware\IdentifyClient;
use App\Limits\QuestionLimiter;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class QuestionLimiterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fatwarag.ask.cache_store' => 'array', 'fatwarag.bff_key' => 'secret-bff-key']);

        Route::middleware(IdentifyClient::class)->get('/_test/client-ip', fn (Request $request) => [
            'ip' => $request->attributes->get('client_ip'),
        ]);
    }

    public function test_forwarded_ip_ignored_without_valid_key(): void
    {
        $this->getJson('/_test/client-ip', ['X-Client-IP' => '203.0.113.9'])
            ->assertJson(['ip' => '127.0.0.1']);

        $this->getJson('/_test/client-ip', ['X-Client-IP' => '203.0.113.9', 'X-BFF-Key' => 'wrong'])
            ->assertJson(['ip' => '127.0.0.1']);

        $this->getJson('/_test/client-ip', ['X-Client-IP' => '203.0.113.9', 'X-BFF-Key' => 'secret-bff-key'])
            ->assertJson(['ip' => '203.0.113.9']);
    }

    public function test_bff_request_without_valid_visitor_ip_is_rejected(): void
    {
        $this->getJson('/_test/client-ip', ['X-BFF-Key' => 'secret-bff-key'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation');

        $this->getJson('/_test/client-ip', ['X-Client-IP' => 'not-an-ip', 'X-BFF-Key' => 'secret-bff-key'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation');
    }

    public function test_forwarded_ipv6_is_accepted_from_bff(): void
    {
        $this->getJson('/_test/client-ip', ['X-Client-IP' => '2001:db8::1', 'X-BFF-Key' => 'secret-bff-key'])
            ->assertJson(['ip' => '2001:db8::1']);
    }

    public function test_daily_cap_blocks_after_limit(): void
    {
        Setting::put('daily_questions', 2);
        $limiter = app(QuestionLimiter::class);

        $this->assertNull($limiter->attempt('198.51.100.1'));
        $this->assertNull($limiter->attempt('198.51.100.2'));
        $this->assertSame('daily_cap_reached', $limiter->attempt('198.51.100.3'));
    }

    public function test_hourly_limit_is_per_visitor(): void
    {
        Setting::put('hourly_questions_per_visitor', 2);
        $limiter = app(QuestionLimiter::class);

        $this->assertNull($limiter->attempt('198.51.100.1'));
        $this->assertNull($limiter->attempt('198.51.100.1'));
        $this->assertSame('rate_limited', $limiter->attempt('198.51.100.1'));
        $this->assertNull($limiter->attempt('198.51.100.2'));
    }

    public function test_concurrent_attempts_at_the_cap_let_only_one_through(): void
    {
        Setting::put('daily_questions', 3);
        $limiter = app(QuestionLimiter::class);

        $this->assertNull($limiter->attempt('198.51.100.1'));
        $this->assertNull($limiter->attempt('198.51.100.2'));

        // Two requests racing for the last slot: exactly one gets it.
        $results = [$limiter->attempt('198.51.100.3'), $limiter->attempt('198.51.100.4')];
        $this->assertSame([null, 'daily_cap_reached'], $results);

        // Refused attempts are rolled back, so raising the cap by one frees exactly one slot.
        Setting::put('daily_questions', 4);
        $this->assertNull($limiter->attempt('198.51.100.5'));
        $this->assertSame('daily_cap_reached', $limiter->attempt('198.51.100.6'));
    }

    public function test_rate_limited_attempt_does_not_use_up_the_daily_cap(): void
    {
        Setting::put('daily_questions', 2);
        Setting::put('hourly_questions_per_visitor', 1);
        $limiter = app(QuestionLimiter::class);

        $this->assertNull($limiter->attempt('198.51.100.1'));
        $this->assertSame('rate_limited', $limiter->attempt('198.51.100.1'));
        $this->assertSame('rate_limited', $limiter->attempt('198.51.100.1'));
        $this->assertNull($limiter->attempt('198.51.100.2'));
    }

    public function test_ipv6_addresses_in_the_same_64_share_one_bucket(): void
    {
        Setting::put('hourly_questions_per_visitor', 1);
        $limiter = app(QuestionLimiter::class);

        $this->assertNull($limiter->attempt('2001:db8:1:2::1'));
        $this->assertSame('rate_limited', $limiter->attempt('2001:db8:1:2:ffff:ffff:ffff:ffff'));
        $this->assertNull($limiter->attempt('2001:db8:1:3::1'));
    }

    public function test_ipv4_mapped_ipv6_counts_as_ipv4(): void
    {
        $this->assertSame('198.51.100.7', QuestionLimiter::bucket('::ffff:198.51.100.7'));
        $this->assertSame('2001:db8:1:2::/64', QuestionLimiter::bucket('2001:db8:1:2:aaaa::1'));
    }

    public function test_redis_counters_are_atomic_and_expire(): void
    {
        config(['fatwarag.ask.cache_store' => 'redis', 'cache.prefix' => 'fatwarag-test-'.uniqid().'-']);

        try {
            Cache::store('redis')->get('ping');
        } catch (\Throwable) {
            $this->markTestSkipped('Local Redis is not reachable.');
        }

        Setting::put('daily_questions', 1);
        $limiter = app(QuestionLimiter::class);

        try {
            $this->assertNull($limiter->attempt('198.51.100.1'));
            $this->assertSame('daily_cap_reached', $limiter->attempt('198.51.100.2'));

            $store = Cache::store('redis')->getStore();
            $ttl = $store->connection()->ttl($store->getPrefix().'ask:daily:'.now()->utc()->format('Y-m-d'));
            $this->assertGreaterThan(0, $ttl);
            $this->assertSame(1, (int) Cache::store('redis')->get('ask:daily:'.now()->utc()->format('Y-m-d')));
        } finally {
            // Only this test's keys (unique prefix); never flush, the Redis database is shared.
            $hour = now()->utc()->format('Y-m-d-H');
            Cache::store('redis')->forget('ask:daily:'.now()->utc()->format('Y-m-d'));
            Cache::store('redis')->forget('ask:hourly:'.hash('sha256', '198.51.100.1').':'.$hour);
            Cache::store('redis')->forget('ask:hourly:'.hash('sha256', '198.51.100.2').':'.$hour);
        }
    }

    public function test_hourly_limit_resets_next_hour(): void
    {
        Setting::put('hourly_questions_per_visitor', 1);
        $limiter = app(QuestionLimiter::class);

        $this->assertNull($limiter->attempt('198.51.100.1'));
        $this->assertSame('rate_limited', $limiter->attempt('198.51.100.1'));

        $this->travel(1)->hours();

        $this->assertNull($limiter->attempt('198.51.100.1'));
    }
}
