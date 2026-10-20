<?php

namespace App\Services\AdminApi;

use App\Models\ApiLog;
use Throwable;

/** Writes one api_logs row per call. A logging failure must never break the vendor's request. */
class ApiLogger
{
    public function __construct(private readonly ApiLogRedactor $redactor) {}

    /**
     * @param  array<string,mixed>  $requestHeaders
     */
    public function record(
        string $service,
        string $method,
        string $url,
        array $requestHeaders,
        ?string $requestBody,
        ?int $status,
        ?string $responseBody,
        int $durationMs,
        ?string $userPublicId = null,
        ?string $error = null,
        string $direction = 'out',
    ): void {
        try {
            ApiLog::create([
                'direction' => $direction,
                'service' => $service,
                'method' => strtoupper($method),
                'url' => mb_substr(strtok($url, '?') ?: $url, 0, 2048), // query strings are never logged
                'route' => parse_url($url, PHP_URL_PATH) ?: null,
                'user_public_id' => $userPublicId,
                'request_headers' => $this->redactor->headers($requestHeaders),
                'request_body' => $this->redactor->body($requestBody),
                'response_status' => $status,
                'response_body' => $this->redactor->body($responseBody),
                'duration_ms' => $durationMs,
                'error' => $error === null ? null : mb_substr($error, 0, 500),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
