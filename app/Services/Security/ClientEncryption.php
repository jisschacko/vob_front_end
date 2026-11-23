<?php

namespace App\Services\Security;

use Illuminate\Contracts\Session\Session;

/**
 * Browser -> VOB encryption of the sign-in credentials ("banking style" form posts). Separate from, and not related to, the Admin API
 * envelope (App\Services\AdminApi\CredentialEnvelope): different key pair, different purpose.
 *
 * Why: the e-mail and password never travel as readable form fields, so they cannot show up in access logs, WAF / proxy captures,
 * browser history or error dumps. It does NOT replace HTTPS (the page that carries the public key must itself come over HTTPS) and cannot
 * stop script running inside the page; XSS protection (CSP, output escaping, sanitising) does that.
 *
 * Scheme (same hybrid as the Admin envelope, done with the browser's WebCrypto): the page carries the VOB public key (RSA, from the owner-created
 * key pair in .env) and a one-time server nonce. The browser makes a random AES-256-GCM key, encrypts {f: fields, n: nonce}, wraps the AES key with
 * RSA-OAEP (SHA-1, the only OAEP hash PHP's openssl supports) and posts one hidden field `enc` = base64url(JSON {ek, iv, ct}); ct = ciphertext || 16-byte tag.
 * The server unwraps with the private key, checks the nonce (issued for this session, used once, not older than NONCE_TTL) and hands the fields back.
 */
class ClientEncryption
{
    public const NONCE_TTL = 1800; // seconds a rendered form stays valid

    private const SESSION_KEY = 'client_enc.nonces';

    private const MAX_OPEN_FORMS = 5; // several tabs may be open at once

    public function __construct(private readonly Session $session) {}

    /** Keys configured (both files readable). */
    public function enabled(): bool
    {
        return $this->publicDer() !== null && $this->privateKey() !== null;
    }

    /** Production never accepts readable credentials: without keys the sign-in pages stay closed. */
    public function required(): bool
    {
        return app()->isProduction();
    }

    /** @return array{key:string, nonce:string}|null public key (base64 SPKI DER) and a one-time nonce for the form being rendered; null when off */
    public function issue(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $nonce = bin2hex(random_bytes(24));
        $open = array_filter((array) $this->session->get(self::SESSION_KEY, []), fn ($at) => $at > now()->getTimestamp() - self::NONCE_TTL);
        $open[$nonce] = now()->getTimestamp();
        $this->session->put(self::SESSION_KEY, array_slice($open, -self::MAX_OPEN_FORMS, null, true));

        return ['key' => base64_encode((string) $this->publicDer()), 'nonce' => $nonce];
    }

    /**
     * @param  list<string>  $allowed  field names that may be carried (anything else is dropped)
     * @return array<string,string>|null the decrypted fields, or null for anything wrong (bad format, tampering, wrong / used / expired nonce)
     */
    public function open(string $enc, array $allowed): ?array
    {
        $private = $this->privateKey();
        if ($private === null || strlen($enc) > 4096) {
            return null;
        }

        $envelope = json_decode((string) base64_decode(strtr($enc, '-_', '+/'), true), true);
        if (! is_array($envelope)) {
            return null;
        }
        $wrapped = base64_decode((string) ($envelope['ek'] ?? ''), true);
        $iv = base64_decode((string) ($envelope['iv'] ?? ''), true);
        $ct = base64_decode((string) ($envelope['ct'] ?? ''), true);
        if ($wrapped === false || $iv === false || $ct === false || strlen($iv) !== 12 || strlen($ct) <= 16) {
            return null;
        }

        if (! @openssl_private_decrypt($wrapped, $aesKey, $private, OPENSSL_PKCS1_OAEP_PADDING) || strlen((string) $aesKey) !== 32) {
            return null;
        }
        $plain = openssl_decrypt(substr($ct, 0, -16), 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, substr($ct, -16));
        $data = $plain === false ? null : json_decode($plain, true);
        if (! is_array($data) || ! is_string($data['n'] ?? null) || ! is_array($data['f'] ?? null)) {
            return null;
        }

        // the nonce was issued to this session, is used once and has not expired
        $open = (array) $this->session->get(self::SESSION_KEY, []);
        $issued = $open[$data['n']] ?? null;
        unset($open[$data['n']]);
        $this->session->put(self::SESSION_KEY, $open);
        if (! is_int($issued) || $issued < now()->getTimestamp() - self::NONCE_TTL) {
            return null;
        }

        $fields = [];
        foreach ($allowed as $name) {
            if (isset($data['f'][$name]) && is_string($data['f'][$name])) {
                $fields[$name] = $data['f'][$name];
            }
        }

        return $fields;
    }

    private function publicDer(): ?string
    {
        $pem = $this->read(config('vob.client_encryption.public_key_path'));
        if ($pem === null || ($key = openssl_pkey_get_public($pem)) === false) {
            return null;
        }

        $body = preg_replace('/-----[^-]+-----|\s+/', '', (string) openssl_pkey_get_details($key)['key']);
        $der = base64_decode((string) $body, true);

        return $der === false || $der === '' ? null : $der;
    }

    private function privateKey(): ?\OpenSSLAsymmetricKey
    {
        $pem = $this->read(config('vob.client_encryption.private_key_path'));
        $key = $pem === null ? false : openssl_pkey_get_private($pem);

        return $key === false ? null : $key;
    }

    private function read(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }
        $file = collect([$path, base_path($path)])->first(fn ($candidate) => is_file($candidate));
        $contents = $file ? file_get_contents($file) : false;

        return $contents === false ? null : $contents;
    }
}
