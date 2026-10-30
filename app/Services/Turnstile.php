<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies Cloudflare Turnstile tokens (managed mode). Development uses Cloudflare's official always-pass test secret.
 */
class Turnstile
{
    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! $token) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(10)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array_filter([
                'secret' => config('fatwarag.turnstile.secret'),
                'response' => $token,
                'remoteip' => $ip,
            ]));

            return (bool) $response->json('success');
        } catch (Throwable) {
            return false;
        }
    }
}
