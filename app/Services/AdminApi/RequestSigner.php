<?php

namespace App\Services\AdminApi;

/** HMAC headers for every Admin API call (docs/API_CONTRACT.md section 2). */
class RequestSigner
{
    public function __construct(private readonly ?string $secret) {}

    /**
     * @param  string  $path  path only, no host and no query string (e.g. /api/v1/tots/abc)
     * @param  string  $rawBody  the exact bytes that will be sent
     * @return array<string,string>
     */
    public function headers(string $method, string $path, string $rawBody, ?int $timestamp = null, ?string $nonce = null): array
    {
        if (blank($this->secret)) {
            throw new AdminApiNotConfiguredException('ADMIN_API_HMAC_SECRET is not configured.');
        }

        $timestamp ??= time();
        $nonce ??= bin2hex(random_bytes(16)); // 32 chars, within the 16-128 the Admin accepts

        $payload = implode("\n", [strtoupper($method), $path, $timestamp, $nonce, hash('sha256', $rawBody)]);

        return [
            'X-Timestamp' => (string) $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => hash_hmac('sha256', $payload, $this->secret),
        ];
    }
}
