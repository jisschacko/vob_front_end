<?php

use App\Http\Middleware\EnsureVendorSession;
use App\Http\Middleware\SecurityHeaders;
use App\Services\AdminApi\AdminApiException;
use App\Services\AdminApi\AdminApiUnauthenticatedException;
use App\Services\AdminApi\AdminSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->validateCsrfTokens(except: ['webhooks/cashfree']);
        $middleware->alias(['vendor' => EnsureVendorSession::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(AdminApiUnauthenticatedException::class);

        // Admin session ended (idle 30 min / 8 h absolute / token replaced): back to sign-in. The invitation link stays in the session.
        $exceptions->render(fn (AdminApiUnauthenticatedException $e, Request $request) => $request->expectsJson()
            ? response()->json(['error' => 'session_ended'], 401)
            : redirect()->route(app(AdminSession::class)->loginRoute())->with('status', 'Your session has ended. Please sign in again.'));

        // Admin unreachable or VOB mis-configured: a neutral page, never the reason.
        $exceptions->render(fn (AdminApiException $e, Request $request) => response()->view('errors.service-unavailable', [], 503));
    })->create();
