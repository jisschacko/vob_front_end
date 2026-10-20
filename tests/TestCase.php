<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No real secret is ever stored in the repo: throwaway values are made fresh for every test run.
        config([
            'admin_api.base_url' => 'https://admin.test/api/v1',
            'admin_api.hmac_secret' => base64_encode(random_bytes(48)),
            'admin_api.retry_sleep_ms' => 0,
        ]);
    }

    public function createApplication()
    {
        // The app key encrypts the session; there is no .env in CI, so use a throwaway key for this process only.
        $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
        putenv('APP_KEY='.$_ENV['APP_KEY']);

        return parent::createApplication();
    }
}
