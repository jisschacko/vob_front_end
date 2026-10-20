<?php

namespace App\Providers;

use App\Services\AdminApi\CredentialEnvelope;
use App\Services\AdminApi\RequestSigner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RequestSigner::class, fn () => new RequestSigner(config('admin_api.hmac_secret')));

        $this->app->bind(CredentialEnvelope::class, fn () => new CredentialEnvelope(
            config('admin_api.rsa_public_key_path'),
            (string) config('admin_api.rsa_key_id'),
        ));
    }

    public function boot(): void
    {
        // Sign-in attempts: 10/min per IP. Account lockout (5 failures / 15 min) is enforced by the Admin only, so an Admin
        // user can clear it immediately (docs/ADMIN_PANEL_CHANGES.md); VOB keeps no per-account block of its own.
        RateLimiter::for('sign-in', fn (Request $request) => Limit::perMinute(10)->by('ip:'.$request->ip()));
    }
}
