<?php

use App\Services\AdminApi\AdminApiNotConfiguredException;
use App\Services\AdminApi\RequestSigner;

it('signs METHOD, PATH, timestamp, nonce and body hash exactly as the contract says', function () {
    $headers = (new RequestSigner('s3cret'))->headers('put', '/api/v1/tots/abc', '{"a":1}', 1760000000, 'n0nce-n0nce-n0nce');

    $expected = hash_hmac('sha256', "PUT\n/api/v1/tots/abc\n1760000000\nn0nce-n0nce-n0nce\n".hash('sha256', '{"a":1}'), 's3cret');

    expect($headers)->toBe([
        'X-Timestamp' => '1760000000',
        'X-Nonce' => 'n0nce-n0nce-n0nce',
        'X-Signature' => $expected,
    ]);
});

it('hashes an empty body for GET and uses a fresh 32-char nonce each time', function () {
    $signer = new RequestSigner('s3cret');
    $a = $signer->headers('GET', '/api/v1/masters', '');
    $b = $signer->headers('GET', '/api/v1/masters', '');

    expect($a['X-Nonce'])->toHaveLength(32)->not->toBe($b['X-Nonce'])
        ->and($a['X-Signature'])->toMatch('/^[0-9a-f]{64}$/')
        ->and(abs((int) $a['X-Timestamp'] - time()))->toBeLessThan(5);
});

it('refuses to sign without a configured secret', function () {
    (new RequestSigner(null))->headers('GET', '/x', '');
})->throws(AdminApiNotConfiguredException::class);
