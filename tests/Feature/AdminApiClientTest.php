<?php

use App\Models\ApiLog;
use App\Services\AdminApi\AdminApiClient;
use App\Services\AdminApi\AdminApiNotConfiguredException;
use App\Services\AdminApi\AdminApiUnauthenticatedException;
use App\Services\AdminApi\AdminApiUnavailableException;
use App\Services\AdminApi\AdminSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Rsa;

uses(RefreshDatabase::class);

const FAKE_TOKEN = 'T0KEN-0123456789-0123456789-0123456789-0123456789-0123456789ab';

function loginPayload(): array
{
    return [
        'access_token' => FAKE_TOKEN, 'token_type' => 'Bearer', 'expires_in' => 1800,
        'user' => ['id' => 'USERPUBLICID0001', 'name' => 'Acme Foods', 'email' => 'v@acme.test', 'must_change_password' => true],
        'tot' => ['id' => 'TOTPUBLICID00001', 'status' => 'pending_vendor', 'editable' => true],
    ];
}

/** Same check the Admin does: recompute the HMAC from what was actually sent. */
function signatureIsValid(Request $request): bool
{
    $path = parse_url($request->url(), PHP_URL_PATH);
    $payload = implode("\n", [$request->method(), $path, $request->header('X-Timestamp')[0], $request->header('X-Nonce')[0], hash('sha256', $request->body())]);

    return hash_equals(hash_hmac('sha256', $payload, config('admin_api.hmac_secret')), $request->header('X-Signature')[0]);
}

beforeEach(function () {
    $this->keys = Rsa::pair();
    config(['admin_api.rsa_public_key_path' => $this->keys['publicPath'], 'admin_api.rsa_key_id' => 'k1']);
    $this->client = app(AdminApiClient::class);
});

it('resolves an invitation link with a signed request and no bearer token', function () {
    Http::fake(['admin.test/*' => Http::response(['valid' => true, 'vendor_name' => 'Acme', 'first_time_login' => true])]);

    $response = $this->client->resolveLink('LINK-TOKEN-VALUE');

    expect($response->json('valid'))->toBeTrue();
    Http::assertSent(function (Request $r) {
        return $r->url() === 'https://admin.test/api/v1/links/resolve'
            && $r->method() === 'POST'
            && json_decode($r->body(), true) === ['token' => 'LINK-TOKEN-VALUE']
            && $r->hasHeader('Accept', 'application/json')
            && ! $r->hasHeader('Authorization')
            && signatureIsValid($r);
    });
});

it('logs in with credentials only inside the encrypted X-Auth-Data header and keeps the token server-side', function () {
    Http::fake(['admin.test/*' => Http::response(loginPayload())]);

    $response = $this->client->login('v@acme.test', 'Temp#Pass1', 'LINK-TOKEN-VALUE');

    expect($response->successful())->toBeTrue();

    Http::assertSent(function (Request $r) {
        $body = json_decode($r->body(), true);
        $envelope = json_decode(base64_decode(strtr($r->header('X-Auth-Data')[0], '-_', '+/')), true);
        openssl_private_decrypt(base64_decode($envelope['ek']), $aesKey, $this->keys['privatePem'], OPENSSL_PKCS1_OAEP_PADDING);
        $fields = json_decode(openssl_decrypt(base64_decode($envelope['ct']), 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, base64_decode($envelope['iv']), base64_decode($envelope['tag'])), true);

        return $r->url() === 'https://admin.test/api/v1/vendor/login'
            && $fields['email'] === 'v@acme.test' && $fields['password'] === 'Temp#Pass1' && $fields['link_token'] === 'LINK-TOKEN-VALUE'
            // decoy body: random fields, never the real credentials
            && array_keys($body) === ['username', 'password'] && $body['password'] !== 'Temp#Pass1'
            && ! str_contains($r->body(), 'v@acme.test') && ! str_contains($r->url(), 'acme')
            && ! $r->hasHeader('Authorization')
            && signatureIsValid($r);
    });

    $session = app(AdminSession::class);
    expect($session->token())->toBe(FAKE_TOKEN)
        ->and($session->userPublicId())->toBe('USERPUBLICID0001')
        ->and($session->mustChangePassword())->toBeTrue()
        ->and($session->tot()['id'])->toBe('TOTPUBLICID00001');
});

it('does not store a session when login fails and passes the status through', function (int $status, array $body) {
    Http::fake(['admin.test/*' => Http::response($body, $status)]);

    $response = $this->client->login('v@acme.test', 'wrong');

    expect($response->status())->toBe($status)
        ->and(app(AdminSession::class)->check())->toBeFalse();
})->with([
    '401 invalid credentials' => [401, ['error' => 'invalid_credentials']],
    '429 too many attempts' => [429, ['error' => 'too_many_attempts']],
    '404 request rejected' => [404, []],
    '422 link invalid' => [422, ['error' => 'link_invalid']],
]);

it('sends the bearer token and a valid signature on authenticated calls, signing the path without the query', function () {
    Http::fake(['admin.test/*' => Http::sequence()->push(loginPayload())->push(['data' => []])]);

    $this->client->login('v@acme.test', 'Temp#Pass1');
    $this->client->get('tots', ['page' => 2]);

    Http::assertSent(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/tots')
        && $r->url() === 'https://admin.test/api/v1/tots?page=2'
        && $r->hasHeader('Authorization', 'Bearer '.FAKE_TOKEN)
        && $r->body() === ''
        && signatureIsValid($r));
});

it('sends JSON bodies for PUT, POST and DELETE', function () {
    Http::fake(['admin.test/*' => Http::sequence()->push(loginPayload())->push(['saved' => ['bank']])->push(['document' => []], 201)->push([], 204)]);

    $this->client->login('v@acme.test', 'Temp#Pass1');
    $this->client->put('tots/ABC/draft', ['form' => ['bank' => ['account_name' => 'Acme']]]);
    $this->client->post('tots/ABC/documents', ['type' => 'pan_certificate']);
    $this->client->delete('tots/ABC/documents/9');

    $methods = collect(Http::recorded())->map(fn ($pair) => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))->all();
    expect($methods)->toBe([
        'POST /api/v1/vendor/login', 'PUT /api/v1/tots/ABC/draft', 'POST /api/v1/tots/ABC/documents', 'DELETE /api/v1/tots/ABC/documents/9',
    ]);
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['form']['bank']['account_name'] === 'Acme' && signatureIsValid($r));
});

it('ends the session and throws on a 401 from a bearer call', function () {
    Http::fake(['admin.test/*' => Http::sequence()->push(loginPayload())->push(['error' => 'unauthenticated'], 401)]);

    $this->client->login('v@acme.test', 'Temp#Pass1');

    expect(fn () => $this->client->get('masters'))->toThrow(AdminApiUnauthenticatedException::class);
    expect(app(AdminSession::class)->check())->toBeFalse();
});

it('throws without calling the API when there is no session', function () {
    Http::fake();

    expect(fn () => $this->client->get('masters'))->toThrow(AdminApiUnauthenticatedException::class);
    Http::assertNothingSent();
});

it('retries a GET on a connection failure or 5xx with a new nonce and signature each time', function () {
    Http::fake(['admin.test/*' => Http::sequence()
        ->push(loginPayload())
        ->pushFailedConnection()
        ->push(['error' => 'down'], 503)
        ->push(['data' => ['ok']])]);

    $this->client->login('v@acme.test', 'Temp#Pass1');
    $response = $this->client->get('masters');

    expect($response->json('data'))->toBe(['ok']);

    $gets = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => $r->method() === 'GET')->values();
    expect($gets)->toHaveCount(3)
        ->and($gets->map(fn ($r) => $r->header('X-Nonce')[0])->unique())->toHaveCount(3)
        ->and($gets->every(fn ($r) => signatureIsValid($r)))->toBeTrue();
});

it('never retries a POST (submit, login) and reports an unreachable API', function () {
    Http::fake(['admin.test/*' => Http::sequence()->push(loginPayload())->pushFailedConnection()->push([])]);

    $this->client->login('v@acme.test', 'Temp#Pass1');

    expect(fn () => $this->client->post('tots/ABC/submit'))->toThrow(AdminApiUnavailableException::class);
    Http::assertSentCount(2);
});

it('gives up with AdminApiUnavailableException once retries are exhausted', function () {
    config(['admin_api.retries' => 1]);
    Http::fake(['admin.test/*' => Http::sequence()->push(loginPayload())->pushFailedConnection()->pushFailedConnection()]);

    $this->client->login('v@acme.test', 'Temp#Pass1');

    expect(fn () => $this->client->get('masters'))->toThrow(AdminApiUnavailableException::class);
    Http::assertSentCount(3);
});

it('changes the password through X-Auth-Data and clears the must-change flag', function () {
    Http::fake(['admin.test/*' => Http::sequence()->push(loginPayload())->push(['message' => 'ok'])]);

    $this->client->login('v@acme.test', 'Temp#Pass1');
    $this->client->changePassword('Temp#Pass1', 'Brand#New-Pass9');

    Http::assertSent(function (Request $r) {
        return str_ends_with($r->url(), '/vendor/password')
            && $r->hasHeader('Authorization', 'Bearer '.FAKE_TOKEN)
            && $r->hasHeader('X-Auth-Data')
            && ! str_contains($r->body(), 'Brand#New-Pass9') && ! str_contains($r->body(), 'Temp#Pass1');
    });
    expect(app(AdminSession::class)->mustChangePassword())->toBeFalse();
});

it('logs out and forgets the token even when the API is down', function () {
    Http::fake(['admin.test/*' => Http::sequence()->push(loginPayload())->pushFailedConnection()]);

    $this->client->login('v@acme.test', 'Temp#Pass1');
    $this->client->logout();

    expect(app(AdminSession::class)->check())->toBeFalse();
});

it('fails clearly when the API is not configured', function (string $key) {
    config([$key => null]);
    Http::fake();

    expect(fn () => app(AdminApiClient::class)->resolveLink('x'))->toThrow(AdminApiNotConfiguredException::class);
    Http::assertNothingSent();
})->with(['admin_api.base_url', 'admin_api.hmac_secret']);

it('fails clearly, before any request, when the RSA public key is missing', function () {
    config(['admin_api.rsa_public_key_path' => '/no/such/key.pem']);
    Http::fake();

    expect(fn () => app(AdminApiClient::class)->login('v@acme.test', 'x'))->toThrow(AdminApiNotConfiguredException::class);
    Http::assertNothingSent();
});

describe('api_logs', function () {
    it('records every call with secrets redacted and numbers masked', function () {
        Http::fake(['admin.test/*' => Http::sequence()
            ->push(loginPayload())
            ->push(['form' => ['bank' => ['account_no' => '123456789012'], 'vendor_details' => ['pan_no' => 'ABCDE1234F', 'gst_no' => '29ABCDE1234F1Z5']]])]);

        $this->client->login('v@acme.test', 'Temp#Pass1', 'LINK-TOKEN-VALUE');
        $this->client->get('tots/ABC');

        $login = ApiLog::where('route', '/api/v1/vendor/login')->sole();
        expect($login->service)->toBe('admin_api')
            ->and($login->direction)->toBe('out')
            ->and($login->method)->toBe('POST')
            ->and($login->response_status)->toBe(200)
            ->and($login->request_headers['X-Auth-Data'])->toBe('[REDACTED]')
            ->and($login->request_headers['X-Signature'])->toBe('[REDACTED]')
            ->and(json_decode($login->request_body, true)['password'])->toBe('[REDACTED]')
            ->and(json_decode($login->response_body, true)['access_token'])->toBe('[REDACTED]');

        $get = ApiLog::where('route', '/api/v1/tots/ABC')->sole();
        expect($get->user_public_id)->toBe('USERPUBLICID0001')
            ->and($get->request_headers['Authorization'])->toBe('[REDACTED]')
            ->and($get->response_body)->toContain('********9012', '******234F', '***********F1Z5')
            ->and($get->response_body)->not->toContain('123456789012')->not->toContain('ABCDE1234F');

        // nothing sensitive anywhere in the table
        $everything = ApiLog::all()->toJson();
        foreach ([FAKE_TOKEN, 'Temp#Pass1', 'LINK-TOKEN-VALUE'] as $secret) {
            expect($everything)->not->toContain($secret);
        }
    });

    it('records each retry attempt and connection errors', function () {
        Http::fake(['admin.test/*' => Http::sequence()->push(loginPayload())->pushFailedConnection()->push(['data' => []])]);

        $this->client->login('v@acme.test', 'Temp#Pass1');
        $this->client->get('masters');

        $rows = ApiLog::where('route', '/api/v1/masters')->orderBy('id')->get();
        expect($rows)->toHaveCount(2)
            ->and($rows[0]->response_status)->toBeNull()
            ->and($rows[0]->error)->toStartWith('Connection failed')
            ->and($rows[1]->response_status)->toBe(200);
    });

    it('does not log query strings', function () {
        Http::fake(['admin.test/*' => Http::sequence()->push(loginPayload())->push([])]);

        $this->client->login('v@acme.test', 'Temp#Pass1');
        $this->client->get('tots', ['q' => 'secret-search']);

        expect(ApiLog::where('route', '/api/v1/tots')->sole()->url)->toBe('https://admin.test/api/v1/tots');
    });

    it('never breaks the request if the log write fails', function () {
        Http::fake(['admin.test/*' => Http::response(['valid' => true])]);
        Schema::drop('api_logs');

        expect($this->client->resolveLink('x')->json('valid'))->toBeTrue();
    });
});
