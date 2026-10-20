<?php

namespace App\Services\AdminApi;

/** Removes secrets and masks account / PAN / GST numbers before anything is written to api_logs. */
class ApiLogRedactor
{
    public const REDACTED = '[REDACTED]';

    private const MAX_BODY = 20000;

    /** Header names whose values are never logged. */
    private const SENSITIVE_HEADERS = ['authorization', 'x-auth-data', 'x-signature', 'x-nonce', 'cookie', 'set-cookie', 'x-csrf-token', 'x-xsrf-token'];

    /** Body keys removed completely. A key is also removed when it contains "password" or "token". */
    private const SENSITIVE_KEYS = ['secret', 'authorization', 'otp', 'cvv', 'ek', 'iv', 'ct', 'tag', 'client_secret', 'x_auth_data'];

    /** Body keys kept only as "******1234". */
    private const MASKED_KEYS = ['account_no', 'account_number', 'bank_account_no', 'beneficiary_account', 'pan', 'pan_no', 'pan_number', 'gst', 'gst_no', 'gstin', 'gst_number'];

    /** @param array<string,mixed> $headers */
    public function headers(array $headers): array
    {
        $clean = [];
        foreach ($headers as $name => $value) {
            $clean[$name] = in_array(strtolower((string) $name), self::SENSITIVE_HEADERS, true) ? self::REDACTED : $value;
        }

        return $clean;
    }

    /** @return string|null redacted body, or null when there is none */
    public function body(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            // Not JSON (HTML error page, gateway text...): keep a short, non-sensitive description only.
            return '[non-JSON body, '.strlen($raw).' bytes]';
        }

        $json = json_encode($this->walk($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return strlen($json) > self::MAX_BODY ? substr($json, 0, self::MAX_BODY).'...[truncated]' : $json;
    }

    /** @param array<mixed> $data */
    private function walk(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->walk($value);

                continue;
            }

            $name = $this->normalise((string) $key);
            if ($this->isSensitive($name)) {
                $data[$key] = self::REDACTED;
            } elseif (in_array($name, self::MASKED_KEYS, true) && $value !== null && $value !== '') {
                $data[$key] = $this->mask((string) $value);
            }
        }

        return $data;
    }

    /** Keys such as "vendor_details.pan_no" (validation errors) are judged on their last segment. */
    private function normalise(string $key): string
    {
        $key = strtolower($key);
        $key = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;

        return str_replace('-', '_', $key);
    }

    private function isSensitive(string $name): bool
    {
        return in_array($name, self::SENSITIVE_KEYS, true)
            || str_contains($name, 'password')
            || str_contains($name, 'token');
    }

    private function mask(string $value): string
    {
        $length = mb_strlen($value);

        return $length <= 4
            ? str_repeat('*', $length)
            : str_repeat('*', $length - 4).mb_substr($value, -4);
    }
}
