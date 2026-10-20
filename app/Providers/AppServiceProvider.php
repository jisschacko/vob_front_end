<?php

namespace App\Providers;

use App\Services\AdminApi\CredentialEnvelope;
use App\Services\AdminApi\RequestSigner;
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
        //
    }
}
