<?php

namespace App\Services\Cashfree;

use App\Services\AdminApi\AdminSession;
use App\Services\AdminApi\ApiLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Cashfree Verification Suite client (PAN, GSTIN, IFSC and bank account). Every call is written to api_logs (redacted). */
class CashfreeClient
{
    public function __construct(
        private readonly ApiLogger $logger,
        private readonly AdminSession $session,
    ) {}

    // ---- Verification Suite v2: PAN, GSTIN, bank account (Cashfree does the penny drop) and IFSC ------------------------------
    // These return the raw answer ({status, json}) even for 4xx/5xx, because Cashfree reports "not valid" in several ways
    // (HTTP 200 with valid:false, 404, 422 with a code). App\Services\Cashfree\FieldVerifier turns that into valid / invalid / unavailable.

    /** POST /pan: pan (required), name (optional). */
    public function pan(string $pan): array
    {
        return $this->request('POST', 'pan', ['pan' => $pan]);
    }

    /** POST /gstin: GSTIN (required, upper-case key), business_name (optional). */
    public function gstin(string $gstin): array
    {
        return $this->request('POST', 'gstin', ['GSTIN' => $gstin]);
    }

    /** POST /ifsc: verification_id (required, unique per call) and ifsc. */
    public function ifsc(string $ifsc): array
    {
        return $this->request('POST', 'ifsc', ['verification_id' => 'vobifsc'.bin2hex(random_bytes(10)), 'ifsc' => $ifsc]);
    }

    /** POST /bank-account/sync: Cashfree makes the penny drop itself and answers at once. name is optional (it only adds a name match). */
    public function bankAccount(string $account, string $ifsc, ?string $name): array
    {
        return $this->request('POST', 'bank-account/sync', array_filter(['bank_account' => $account, 'ifsc' => $ifsc, 'name' => $name], fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * One call, any HTTP status. Throws CashfreeException only when no call could be made at all (missing credentials, no connection).
     *
     * @param  array<string,mixed>|null  $body
     * @param  array<string,scalar|null>  $query
     * @return array{status:int,json:array<string,mixed>}
     */
    private function request(string $method, string $path, ?array $body, array $query = []): array
    {
        $id = config('cashfree.client_id');
        $secret = config('cashfree.client_secret');
        $base = config('cashfree.base_urls.'.config('cashfree.env'));
        if (blank($id) || blank($secret) || blank($base)) {
            throw new CashfreeException('Verification is not available right now. Please try again later.');
        }

        $url = rtrim($base, '/').'/'.$path;
        $headers = ['x-client-id' => $id, 'x-client-secret' => $secret, 'Accept' => 'application/json'];
        $raw = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR);
        $started = hrtime(true);

        try {
            $http = Http::withHeaders($headers)->timeout((int) config('cashfree.timeout'))->connectTimeout(5);
            $response = $method === 'GET' ? $http->get($url, $query) : $http->withBody($raw, 'application/json')->send($method, $url);
        } catch (ConnectionException $e) {
            $this->log($method, $url, $headers, $raw, null, null, $started, 'Connection failed: '.$e->getMessage());

            throw new CashfreeException('Verification is not available right now. Please try again later.', 0, $e);
        }

        $this->log($method, $url, $headers, $raw, $response->status(), $response->body(), $started);

        return ['status' => $response->status(), 'json' => is_array($response->json()) ? $response->json() : []];
    }

    private function log(string $method, string $url, array $headers, string $body, ?int $status, ?string $response, int $started, ?string $error = null): void
    {
        $this->logger->record(
            service: 'cashfree',
            method: $method,
            url: $url,
            requestHeaders: $headers,
            requestBody: $body,
            status: $status,
            responseBody: $response,
            durationMs: (int) ((hrtime(true) - $started) / 1e6),
            userPublicId: $this->session->userPublicId(),
            error: $error,
        );
    }
}
