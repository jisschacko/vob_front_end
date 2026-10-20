<?php

namespace App\Services\AdminApi;

/**
 * Builds the X-Auth-Data header: AES-256-GCM payload with a fresh key and IV per request, the key wrapped with the Admin's
 * RSA public key (RSA-OAEP). Mirrors Admin's App\Support\CredentialEnvelope::encrypt (docs/API_CONTRACT.md section 4).
 */
class CredentialEnvelope
{
    public function __construct(
        private readonly ?string $publicKeyPath,
        private readonly string $keyId,
    ) {}

    /** @param array<string,mixed> $fields */
    public function encrypt(array $fields): string
    {
        $publicKey = $this->publicKey();

        $payload = json_encode($fields + ['ts' => time(), 'nonce' => bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR);
        $aesKey = random_bytes(32);
        $iv = random_bytes(12);

        $ciphertext = openssl_encrypt($payload, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false || ! openssl_public_encrypt($aesKey, $wrapped, $publicKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new AdminApiNotConfiguredException('Could not encrypt the credential envelope (check the RSA public key).');
        }

        $json = json_encode([
            'kid' => $this->keyId,
            'ek' => base64_encode($wrapped),
            'iv' => base64_encode($iv),
            'ct' => base64_encode($ciphertext),
            'tag' => base64_encode($tag),
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function publicKey(): \OpenSSLAsymmetricKey
    {
        $path = $this->publicKeyPath;
        if (blank($path)) {
            throw new AdminApiNotConfiguredException('ADMIN_API_RSA_PUBLIC_KEY_PATH is not configured.');
        }

        // an absolute path as given, otherwise relative to the project root
        $file = collect([$path, base_path($path)])->first(fn ($candidate) => is_file($candidate));

        $pem = $file ? file_get_contents($file) : false;
        $key = $pem === false ? false : openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new AdminApiNotConfiguredException('The Admin RSA public key is missing or unreadable.');
        }

        return $key;
    }
}
