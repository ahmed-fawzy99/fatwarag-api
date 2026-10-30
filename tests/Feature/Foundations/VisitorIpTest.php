<?php

namespace Tests\Feature\Foundations;

use App\Http\Middleware\IdentifyClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Pre-release fix: behind Cloudflare and the host's proxy, `ip()` (the admin login throttle) and `client_ip` (every
 * per-visitor limit) are the visitor, and nobody going around Cloudflare can choose them.
 */
class VisitorIpTest extends TestCase
{
    private const DOCKER_GATEWAY = '172.18.0.1';

    private const CLOUDFLARE_EDGE = '162.158.10.20';

    protected function setUp(): void
    {
        parent::setUp();

        config(['fatwarag.bff_key' => 'test-bff-key']);
        Route::get('/_test/visitor-ip', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'client' => $request->attributes->get('client_ip'),
        ]))->middleware(IdentifyClient::class);
    }

    public function test_a_visitor_through_cloudflare_is_identified_by_cf_connecting_ip(): void
    {
        // The host's proxy either replaces X-Forwarded-For with the Cloudflare edge or appends the edge to it.
        foreach ([self::CLOUDFLARE_EDGE, '192.0.2.66, 203.0.113.7, '.self::CLOUDFLARE_EDGE] as $forwardedFor) {
            $this->viaProxy($forwardedFor, ['CF-Connecting-IP' => '203.0.113.7'])
                ->assertExactJson(['ip' => '203.0.113.7', 'client' => '203.0.113.7']);
        }
    }

    public function test_different_visitors_through_cloudflare_get_different_ips(): void
    {
        $this->viaProxy(self::CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '2001:db8::5'])->assertJsonPath('ip', '2001:db8::5');
        $this->viaProxy(self::CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '203.0.113.8'])->assertJsonPath('ip', '203.0.113.8');
    }

    public function test_cf_connecting_ip_is_ignored_when_the_request_did_not_come_from_cloudflare(): void
    {
        // Straight to the host's proxy, which puts the real peer in X-Forwarded-For.
        $this->viaProxy('203.0.113.9', ['CF-Connecting-IP' => '192.0.2.1'])
            ->assertExactJson(['ip' => '203.0.113.9', 'client' => '203.0.113.9']);

        // A connection that isn't a local hop: its forwarded headers aren't trusted either.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['X-Forwarded-For' => self::CLOUDFLARE_EDGE, 'CF-Connecting-IP' => '192.0.2.1'])
            ->getJson('/_test/visitor-ip')
            ->assertExactJson(['ip' => '203.0.113.9', 'client' => '203.0.113.9']);
    }

    public function test_a_bff_request_is_still_limited_by_the_visitor_ip_it_forwards(): void
    {
        $this->viaProxy(self::CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '203.0.113.20', 'X-BFF-Key' => 'test-bff-key', 'X-Client-IP' => '203.0.113.30'])
            ->assertExactJson(['ip' => '203.0.113.20', 'client' => '203.0.113.30']);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function viaProxy(string $forwardedFor, array $headers): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::DOCKER_GATEWAY])
            ->withHeaders(['X-Forwarded-For' => $forwardedFor, ...$headers])
            ->getJson('/_test/visitor-ip')
            ->assertOk();
    }
}
