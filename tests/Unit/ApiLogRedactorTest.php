<?php

use App\Services\AdminApi\ApiLogRedactor;

beforeEach(fn () => $this->r = new ApiLogRedactor);

it('redacts auth headers but keeps harmless ones', function () {
    $out = $this->r->headers(['Authorization' => 'Bearer abc', 'X-Auth-Data' => 'xyz', 'X-Signature' => 'sig', 'Accept' => 'application/json']);

    expect($out)->toBe(['Authorization' => '[REDACTED]', 'X-Auth-Data' => '[REDACTED]', 'X-Signature' => '[REDACTED]', 'Accept' => 'application/json']);
});

it('removes passwords and tokens at any depth', function () {
    $out = json_decode($this->r->body(json_encode([
        'username' => 'decoy', 'password' => 'p1', 'access_token' => 'tok', 'token' => 'link',
        'nested' => ['new_password' => 'n', 'current_password' => 'c'], 'user' => ['name' => 'Acme'],
    ])), true);

    expect($out['password'])->toBe('[REDACTED]')
        ->and($out['access_token'])->toBe('[REDACTED]')
        ->and($out['token'])->toBe('[REDACTED]')
        ->and($out['nested'])->toBe(['new_password' => '[REDACTED]', 'current_password' => '[REDACTED]'])
        ->and($out['user']['name'])->toBe('Acme');
});

it('masks account, PAN and GST values to their last four characters', function () {
    $out = json_decode($this->r->body(json_encode([
        'bank' => ['account_no' => '123456789012', 'ifsc' => 'HDFC0001234'],
        'vendor_details' => ['pan_no' => 'ABCDE1234F', 'gst_no' => '29ABCDE1234F1Z5'],
        'short' => ['account_no' => '12'],
        'empty' => ['pan_no' => null],
    ])), true);

    expect($out['bank']['account_no'])->toBe('********9012')
        ->and($out['bank']['ifsc'])->toBe('HDFC0001234')
        ->and($out['vendor_details']['pan_no'])->toBe('******234F')
        ->and($out['vendor_details']['gst_no'])->toBe('***********F1Z5')
        ->and($out['short']['account_no'])->toBe('**')
        ->and($out['empty']['pan_no'])->toBeNull();
});

it('does not keep non-JSON bodies, and truncates huge ones', function () {
    expect($this->r->body('<html>secret page</html>'))->toBe('[non-JSON body, 24 bytes]')
        ->and($this->r->body(null))->toBeNull()
        ->and($this->r->body(json_encode(['x' => str_repeat('a', 50000)])))->toEndWith('...[truncated]');
});

it('does not mask the message text in errors / warnings keyed by field path', function () {
    $out = json_decode((new ApiLogRedactor)->body(json_encode([
        'warnings' => ['bank.account_no' => 'This field is required.', 'vendor_details.pan_no' => 'This field is required.'],
        'errors' => ['bank.account_no' => ['The account number is invalid.']],
        'form' => ['bank' => ['account_no' => '123456789012']],
    ])), true);

    expect($out['warnings']['bank.account_no'])->toBe('This field is required.')
        ->and($out['errors']['bank.account_no'][0])->toBe('The account number is invalid.')
        ->and($out['form']['bank']['account_no'])->toBe('********9012');
});
