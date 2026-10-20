<?php

namespace App\Services\Cashfree;

use App\Services\AdminApi\AdminSession;
use App\Services\AdminApi\ApiLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Cashfree Reverse Penny Drop: create a request (UPI link + QR) and read its status. Every call is written to api_logs (redacted). */
class CashfreeClient
{
    public function __construct(
        private readonly ApiLogger $logger,
        private readonly AdminSession $session,
    ) {}

    /**
     * POST /reverse-penny-drop
     *
     * @return array<string,mixed> verification_id, ref_id, valid_upto, upi_link, gpay, bhim, paytm, phonepe, qr_code, url
     */
    public function create(string $verificationId, ?string $name, string $redirectUrl): array
    {
        $body = array_filter(['verification_id' => $verificationId, 'name' => $name, 'redirect_url' => $redirectUrl], fn ($v) => $v !== null && $v !== '');

        return $this->send('POST', 'reverse-penny-drop', $body);
    }

    /**
     * GET /remitter/status. status: CREATED, SUCCESS, FAILURE or EXPIRED; on SUCCESS also bank_account, ifsc, name_at_bank, name_match_result...
     *
     * @return array<string,mixed>
     */
    public function status(string $verificationId): array
    {
        return $this->send('GET', 'remitter/status', null, ['verification_id' => $verificationId]);
    }

    /** @return array<string,mixed> */
    private function send(string $method, string $path, ?array $body, array $query = []): array
    {
        $id = config('cashfree.client_id');
        $secret = config('cashfree.client_secret');
        $base = config('cashfree.base_urls.'.config('cashfree.env'));
        if (blank($id) || blank($secret) || blank($base)) {
            throw new CashfreeException('Bank verification is not available right now. Please try again later.');
        }

        $url = rtrim($base, '/').'/'.$path;
        $headers = ['x-client-id' => $id, 'x-client-secret' => $secret, 'Accept' => 'application/json'];
        $raw = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR);
        $started = hrtime(true);

        try {
            $request = Http::withHeaders($headers)->timeout((int) config('cashfree.timeout'))->connectTimeout(5);
            $response = $method === 'GET'
                ? $request->get($url, $query)
                : $request->withBody($raw, 'application/json')->send($method, $url);
        } catch (ConnectionException $e) {
            $this->log($method, $url, $headers, $raw, null, null, $started, 'Connection failed: '.$e->getMessage());

            throw new CashfreeException('Bank verification is not available right now. Please try again later.', 0, $e);
        }

        $this->log($method, $url, $headers, $raw, $response->status(), $response->body(), $started);

        if (! $response->successful() || ! is_array($response->json())) {
            throw new CashfreeException($this->vendorMessage($response), $response->status());
        }

        return $response->json();
    }

    /** Neutral text for the vendor; Cashfree's own code stays in the log. */
    private function vendorMessage(Response $response): string
    {
        return match ($response->status()) {
            429 => 'Too many attempts. Please wait a minute and try again.',
            default => 'Bank verification is not available right now. Please try again later.',
        };
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
