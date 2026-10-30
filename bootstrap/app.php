<?php

use App\Http\Middleware\TrustCloudflare;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Visitor IP behind Cloudflare and the host's proxy (TrustCloudflare): forwarded headers are trusted from the
        // local hops only, then Cloudflare's CF-Connecting-IP when the request came from Cloudflare.
        $middleware->trustProxies(at: TrustCloudflare::LOCAL_HOPS);
        $middleware->append(TrustCloudflare::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
