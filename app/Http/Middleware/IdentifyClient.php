<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the `client_ip` request attribute that per-visitor limits key on. The Next.js BFF calls the API from its server,
 * so it forwards the visitor's IP in `X-Client-IP`; that header is trusted only with a valid `X-BFF-Key`.
 *
 * A BFF request without a valid visitor IP is rejected: falling back to the connecting IP would put every visitor in
 * the BFF server's one bucket.
 */
class IdentifyClient
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isFromBff($request)) {
            $request->attributes->set('client_ip', (string) $request->ip());

            return $next($request);
        }

        $forwarded = trim((string) $request->header('X-Client-IP', ''));

        if (! filter_var($forwarded, FILTER_VALIDATE_IP)) {
            return response()->json([
                'error' => ['code' => 'validation', 'message' => 'X-Client-IP must carry the visitor\'s IP address.'],
            ], 400);
        }

        $request->attributes->set('client_ip', $forwarded);

        return $next($request);
    }

    private function isFromBff(Request $request): bool
    {
        $key = (string) config('fatwarag.bff_key');
        $presented = (string) $request->header('X-BFF-Key', '');

        return $key !== '' && $presented !== '' && hash_equals($key, $presented);
    }
}
