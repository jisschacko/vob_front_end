<?php

namespace App\Services\AdminApi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The only way VOB talks to the Admin Panel API (docs/API_CONTRACT.md).
 *
 * - every call carries the HMAC headers (fresh timestamp + nonce per attempt);
 * - login / password change send credentials only in the encrypted X-Auth-Data header and a random decoy JSON body;
 * - after login the Bearer token is read from the server-side session, never exposed to the browser;
 * - every attempt and its response is written to api_logs (redacted).
 *
 * Non-2xx answers are returned as-is for the caller to interpret, except a 401 on a Bearer call, which ends the session.
 */
class AdminApiClient
{
    private const IDEMPOTENT = ['GET', 'PUT', 'DELETE'];

    public function __construct(
        private readonly RequestSigner $signer,
        private readonly CredentialEnvelope $envelope,
        private readonly ApiLogger $logger,
        private readonly AdminSession $session,
    ) {}

    // ---- Before login (signature only) -------------------------------------------------------------------------------

    /** POST /links/resolve with the token from the invitation URL. */
    public function resolveLink(string $linkToken): Response
    {
        return $this->send('POST', 'links/resolve', ['token' => $linkToken], bearer: false);
    }

    /**
     * POST /vendor/login. On 200 the token and user are stored in the server-side session.
     * A 401 here means wrong credentials, so it is returned, not thrown.
     */
    public function login(string $email, string $password, ?string $linkToken = null): Response
    {
        $fields = ['email' => $email, 'password' => $password];
        if ($linkToken !== null && $linkToken !== '') {
            $fields['link_token'] = $linkToken;
        }

        $response = $this->send('POST', 'vendor/login', $this->decoyBody(), bearer: false, authData: $this->envelope->encrypt($fields));

        if ($response->successful() && is_string($response->json('access_token'))) {
            $this->session->start($response->json());
        }

        return $response;
    }

    /** POST /vendor/password/forgot (before login). The e-mail goes only in X-Auth-Data; the answer is the same for every address. */
    public function forgotPassword(string $email): Response
    {
        return $this->send('POST', 'vendor/password/forgot', $this->decoyBody(), bearer: false, authData: $this->envelope->encrypt(['email' => $email]));
    }

    /** POST /vendor/password/reset (before login). The token from the emailed link and the new password go only in X-Auth-Data. */
    public function resetPassword(string $token, string $newPassword): Response
    {
        return $this->send('POST', 'vendor/password/reset', $this->decoyBody(), bearer: false, authData: $this->envelope->encrypt([
            'token' => $token,
            'new_password' => $newPassword,
        ]));
    }

    // ---- After login (signature + Bearer) ----------------------------------------------------------------------------

    /** POST /vendor/password. Credentials only in X-Auth-Data. */
    public function changePassword(string $currentPassword, string $newPassword): Response
    {
        $response = $this->send('POST', 'vendor/password', $this->decoyBody(), authData: $this->envelope->encrypt([
            'current_password' => $currentPassword,
            'new_password' => $newPassword,
        ]));

        if ($response->successful()) {
            $this->session->markPasswordChanged();
        }

        return $response;
    }

    /** POST /vendor/logout, then forget the token even if Admin was unreachable. */
    public function logout(): void
    {
        try {
            if ($this->session->check()) {
                $this->send('POST', 'vendor/logout', []);
            }
        } catch (AdminApiException) {
            // the local session is cleared below either way
        } finally {
            $this->session->forget();
        }
    }

    /** @param array<string,scalar|null> $query */
    public function get(string $path, array $query = []): Response
    {
        return $this->send('GET', $path, null, query: $query);
    }

    /** @param array<string,mixed> $data */
    public function post(string $path, array $data = []): Response
    {
        return $this->send('POST', $path, $data);
    }

    /** @param array<string,mixed> $data */
    public function put(string $path, array $data = []): Response
    {
        return $this->send('PUT', $path, $data);
    }

    public function delete(string $path): Response
    {
        return $this->send('DELETE', $path, null);
    }

    // ---- Internals ---------------------------------------------------------------------------------------------------

    /**
     * @param  array<string,mixed>|null  $body
     * @param  array<string,scalar|null>  $query
     */
    private function send(string $method, string $path, ?array $body, bool $bearer = true, ?string $authData = null, array $query = []): Response
    {
        $baseUrl = config('admin_api.base_url');
        if (blank($baseUrl)) {
            throw new AdminApiNotConfiguredException('ADMIN_API_BASE_URL is not configured.');
        }

        $token = null;
        if ($bearer) {
            $token = $this->session->token();
            if ($token === null) {
                throw new AdminApiUnauthenticatedException('No active Admin API session.');
            }
        }

        $url = rtrim($baseUrl, '/').'/'.ltrim($path, '/');
        $signedPath = parse_url($url, PHP_URL_PATH) ?: '/';
        $method = strtoupper($method);
        $rawBody = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sendUrl = $query === [] ? $url : $url.'?'.http_build_query($query);

        $attempts = in_array($method, self::IDEMPOTENT, true) ? 1 + max(0, (int) config('admin_api.retries')) : 1;
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            // Fresh timestamp + nonce every attempt: the Admin accepts a nonce only once.
            $headers = ['Accept' => 'application/json'] + $this->signer->headers($method, $signedPath, $rawBody);
            if ($token !== null) {
                $headers['Authorization'] = 'Bearer '.$token;
            }
            if ($authData !== null) {
                $headers['X-Auth-Data'] = $authData;
            }

            $started = hrtime(true);
            try {
                $response = Http::withHeaders($headers)
                    ->withBody($rawBody, 'application/json')
                    ->timeout((int) config('admin_api.timeout'))
                    ->connectTimeout((int) config('admin_api.connect_timeout'))
                    ->send($method, $sendUrl);
            } catch (ConnectionException $e) {
                $this->log($method, $url, $headers, $rawBody, null, null, $started, 'Connection failed: '.$e->getMessage());
                $lastException = $e;
                $this->pause($attempt, $attempts);

                continue;
            }

            $this->log($method, $url, $headers, $rawBody, $response->status(), $response->body(), $started);

            if ($response->serverError() && $attempt < $attempts) {
                $this->pause($attempt, $attempts);

                continue;
            }

            if ($bearer && $response->status() === 401) {
                $this->session->forget();

                throw new AdminApiUnauthenticatedException('The Admin API session has expired.');
            }

            return $response;
        }

        throw new AdminApiUnavailableException('The Admin API could not be reached.', 0, $lastException);
    }

    /** Random fields the Admin ignores; the real credentials travel only in X-Auth-Data. */
    private function decoyBody(): array
    {
        return [
            'username' => bin2hex(random_bytes(8)),
            'password' => bin2hex(random_bytes(12)),
        ];
    }

    private function pause(int $attempt, int $attempts): void
    {
        $ms = (int) config('admin_api.retry_sleep_ms');
        if ($attempt < $attempts && $ms > 0) {
            usleep($ms * 1000 * $attempt);
        }
    }

    /** @param array<string,string> $headers */
    private function log(string $method, string $url, array $headers, string $body, ?int $status, ?string $responseBody, int|float $started, ?string $error = null): void
    {
        $this->logger->record(
            service: 'admin_api',
            method: $method,
            url: $url,
            requestHeaders: $headers,
            requestBody: $body,
            status: $status,
            responseBody: $responseBody,
            durationMs: (int) ((hrtime(true) - $started) / 1e6),
            userPublicId: $this->session->userPublicId(),
            error: $error,
        );
    }
}
