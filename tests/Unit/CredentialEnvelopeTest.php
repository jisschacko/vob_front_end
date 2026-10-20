<?php

use App\Services\AdminApi\AdminApiNotConfiguredException;
use App\Services\AdminApi\CredentialEnvelope;
use Tests\Support\Rsa;

/** Decrypts the way the Admin Panel does (docs/API_CONTRACT.md section 4). */
function openEnvelope(string $header, string $privatePem): array
{
    $env = json_decode(base64_decode(strtr($header, '-_', '+/')), true);
    openssl_private_decrypt(base64_decode($env['ek']), $aesKey, $privatePem, OPENSSL_PKCS1_OAEP_PADDING);
    $json = openssl_decrypt(base64_decode($env['ct']), 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, base64_decode($env['iv']), base64_decode($env['tag']));

    return ['envelope' => $env, 'payload' => json_decode($json, true)];
}

it('produces a header the Admin can decrypt, with ts and nonce added', function () {
    $keys = Rsa::pair();
    $header = (new CredentialEnvelope($keys['publicPath'], 'k1'))->encrypt(['email' => 'v@example.test', 'password' => 'Temp#1234']);

    expect($header)->toMatch('/^[A-Za-z0-9_-]+$/'); // base64url, no padding

    ['envelope' => $env, 'payload' => $p] = openEnvelope($header, $keys['privatePem']);

    expect($env['kid'])->toBe('k1')
        ->and(strlen(base64_decode($env['iv'])))->toBe(12)
        ->and(strlen(base64_decode($env['tag'])))->toBe(16)
        ->and($p['email'])->toBe('v@example.test')
        ->and($p['password'])->toBe('Temp#1234')
        ->and(abs($p['ts'] - time()))->toBeLessThan(5)
        ->and(strlen($p['nonce']))->toBeBetween(16, 128);
});

it('uses a new AES key, IV and nonce for every request', function () {
    $keys = Rsa::pair();
    $envelope = new CredentialEnvelope($keys['publicPath'], 'k1');

    $a = openEnvelope($envelope->encrypt(['x' => 1]), $keys['privatePem']);
    $b = openEnvelope($envelope->encrypt(['x' => 1]), $keys['privatePem']);

    expect($a['envelope']['iv'])->not->toBe($b['envelope']['iv'])
        ->and($a['envelope']['ek'])->not->toBe($b['envelope']['ek'])
        ->and($a['payload']['nonce'])->not->toBe($b['payload']['nonce']);
});

it('fails with a clear error when the public key is missing', function (?string $path) {
    (new CredentialEnvelope($path, 'k1'))->encrypt(['x' => 1]);
})->with([null, '/no/such/vob_public.pem'])->throws(AdminApiNotConfiguredException::class);
