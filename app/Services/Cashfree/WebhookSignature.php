<?php

namespace App\Services\Cashfree;

/**
 * Cashfree RPD webhook signature (docs: "Signature verification"): sort the `data` fields by key, concatenate the values, HMAC-SHA256 with the
 * client secret, Base64. The webhook content is never trusted for the result itself (the status is re-read from Cashfree); this check only
 * stops strangers from triggering status look-ups.
 */
class WebhookSignature
{
    public static function expected(array $payload, string $secret): string
    {
        $data = (array) ($payload['data'] ?? []);
        ksort($data);
        $postData = implode('', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $data));

        return base64_encode(hash_hmac('sha256', $postData, $secret, true));
    }

    public static function valid(array $payload, ?string $secret): bool
    {
        $given = $payload['signature'] ?? null;

        return filled($secret) && is_string($given) && hash_equals(self::expected($payload, $secret), $given);
    }
}
