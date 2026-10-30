<?php

use App\Models\ApiLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Rsa;
use Tests\Support\TotFixtures as T;

uses(RefreshDatabase::class);

const RESET_TOKEN = 'abcdefghij0123456789SECRETTOKENvalue';
const NEUTRAL_MESSAGE = 'If the address belongs to a vendor account, an email with a reset link has been sent.';

/** The fields VOB put in X-Auth-Data, decrypted with the test key. */
function authFields(Request $r, array $keys): array
{
    $envelope = json_decode(base64_decode($r->header('X-Auth-Data')[0]), true);
    openssl_private_decrypt(base64_decode($envelope['ek']), $aesKey, $keys['privatePem'], OPENSSL_PKCS1_OAEP_PADDING);

    return json_decode(openssl_decrypt(base64_decode($envelope['ct']), 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, base64_decode($envelope['iv']), base64_decode($envelope['tag'])), true);
}

beforeEach(function () {
    $this->keys = Rsa::pair();
    config(['admin_api.rsa_public_key_path' => $this->keys['publicPath']]);
});

describe('the Forgot password link', function () {
    it('is on the login page', function () {
        $this->get('/login')->assertOk()->assertSee('Forgot password?')->assertSee(route('password.forgot'), false);
    });

    it('opens a page with an e-mail field that posts with CSRF', function () {
        $html = $this->get('/forgot-password')->assertOk()->getContent();

        expect($html)->toMatch('/<form method="POST" action="[^"]+\/forgot-password"/')->toContain('name="_token"')->toContain('name="email"')->toContain('60 minutes');
    });
});

describe('asking for a reset link', function () {
    it('sends the e-mail only in the encrypted header and always answers with the neutral message', function () {
        T::fake(['POST vendor/password/forgot' => Http::response(['message' => NEUTRAL_MESSAGE])]);

        $this->post('/forgot-password', ['email' => 'sales@acme.test'])
            ->assertRedirect(route('password.forgot'))->assertSessionHas('status', NEUTRAL_MESSAGE);

        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/vendor/password/forgot')) {
                return false;
            }
            expect(authFields($r, $this->keys))->toMatchArray(['email' => 'sales@acme.test'])->not->toHaveKey('password')
                ->and($r->hasHeader('Authorization'))->toBeFalse()
                ->and($r->body())->not->toContain('sales@acme.test');

            return true;
        });
    });

    it('shows the same words whatever the Admin answered for (it is the same 200 for every address)', function () {
        T::fake(['POST vendor/password/forgot' => Http::response(['message' => NEUTRAL_MESSAGE])]);

        $this->followingRedirects()->post('/forgot-password', ['email' => 'nobody@acme.test'])
            ->assertOk()->assertSee(NEUTRAL_MESSAGE)->assertDontSee('not found');
    });

    it('shows a neutral message when rate limited and when the Admin fails', function (int $status, string $text) {
        T::fake(['POST vendor/password/forgot' => Http::response(['error' => 'too_many_attempts', 'retry_after' => 60], $status)]);

        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'sales@acme.test'])
            ->assertRedirect('/forgot-password')->assertSessionHasErrors(['email' => $text]);
    })->with([
        'rate limited' => [429, 'Too many requests, try again later.'],
        'bad envelope' => [404, 'We could not process this request right now. Please try again later.'],
    ]);

    it('checks that the field is an e-mail address', function () {
        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'not-an-email'])->assertSessionHasErrors('email');
    });

    it('is POST only', function () {
        foreach (['put', 'patch', 'delete'] as $verb) {
            $this->$verb('/forgot-password')->assertStatus(405);
        }
    });
});

describe('the emailed link', function () {
    it('moves the token out of the address bar into the session', function () {
        $response = $this->get('/reset-password/'.RESET_TOKEN)->assertRedirect(route('password.reset'));

        expect($response->headers->get('Location'))->not->toContain(RESET_TOKEN);
        $this->get('/reset-password')->assertOk()->assertDontSee(RESET_TOKEN)->assertSee('Choose a new password');
    });

    it('does not put the token anywhere in the reset page', function () {
        $html = $this->withSession(['password_reset.token' => RESET_TOKEN])->get('/reset-password')->assertOk()->getContent();

        expect($html)->not->toContain(RESET_TOKEN)->toContain('name="new_password"')->toContain('autocomplete="new-password"')->toContain('name="_token"');
    });

    it('sends a visitor without a link to the forgot-password page', function () {
        $this->get('/reset-password')->assertRedirect(route('password.forgot'));
        $this->post('/reset-password', ['new_password' => 'Brand#New-Pass9', 'new_password_confirmation' => 'Brand#New-Pass9'])->assertRedirect(route('password.forgot'));
    });
});

describe('choosing the new password', function () {
    $post = fn (string $password = 'Brand#New-Pass9', ?string $confirm = null) => [
        'new_password' => $password, 'new_password_confirmation' => $confirm ?? $password,
    ];

    it('sends token and password only in the encrypted header, then goes to the login page', function () use ($post) {
        T::fake(['POST vendor/password/reset' => Http::response(['message' => 'Password changed.'])]);

        $this->withSession(['password_reset.token' => RESET_TOKEN])->post('/reset-password', $post())
            ->assertRedirect(route('login'))->assertSessionHas('status', 'Your password has been changed. Please sign in.');

        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/vendor/password/reset')) {
                return false;
            }
            expect(authFields($r, $this->keys))->toMatchArray(['token' => RESET_TOKEN, 'new_password' => 'Brand#New-Pass9'])
                ->and($r->hasHeader('Authorization'))->toBeFalse()
                ->and($r->url())->not->toContain(RESET_TOKEN)
                ->and($r->body())->not->toContain(RESET_TOKEN)->not->toContain('Brand#New-Pass9');

            return true;
        });
        expect(session('password_reset.token'))->toBeNull(); // used up
    });

    it('does not sign the vendor in after a reset', function () use ($post) {
        T::fake(['POST vendor/password/reset' => Http::response(['message' => 'ok'])]);

        $this->withSession(['password_reset.token' => RESET_TOKEN])->post('/reset-password', $post());

        $this->get('/home')->assertRedirect(route('login'));
    });

    it('shows the expired-link message and drops the token on token_invalid', function () use ($post) {
        T::fake(['POST vendor/password/reset' => Http::response(['error' => 'token_invalid'], 422)]);

        $this->withSession(['password_reset.token' => RESET_TOKEN])->post('/reset-password', $post())
            ->assertRedirect(route('password.forgot'))
            ->assertSessionHasErrors(['email' => 'This link is invalid or has expired. Ask for a new one below.']);

        expect(session('password_reset.token'))->toBeNull();
    });

    it('keeps the token and shows the Admin message for a weak password so the vendor can try again', function () use ($post) {
        T::fake(['POST vendor/password/reset' => Http::response(['message' => 'x', 'errors' => ['new_password' => ['The new password is too common.']]], 422)]);

        $this->withSession(['password_reset.token' => RESET_TOKEN])->from('/reset-password')->post('/reset-password', $post())
            ->assertRedirect('/reset-password')->assertSessionHasErrors(['new_password' => 'The new password is too common.']);

        expect(session('password_reset.token'))->toBe(RESET_TOKEN);
    });

    it('checks the strength and the confirmation itself before calling the Admin', function (string $password, ?string $confirm) use ($post) {
        T::fake();

        $this->withSession(['password_reset.token' => RESET_TOKEN])->from('/reset-password')->post('/reset-password', $post($password, $confirm))
            ->assertSessionHasErrors('new_password');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'password/reset'));
    })->with([
        'too short' => ['Ab#1', null],
        'no symbol' => ['Abcdefghij12', null],
        'no number' => ['Abcdefghij#!', null],
        'no capital' => ['abcdefghij#1', null],
        'confirmation differs' => ['Brand#New-Pass9', 'Brand#New-Pass0'],
    ]);

    it('shows a neutral message when rate limited and keeps the token', function () use ($post) {
        T::fake(['POST vendor/password/reset' => Http::response(['error' => 'too_many_attempts'], 429)]);

        $this->withSession(['password_reset.token' => RESET_TOKEN])->from('/reset-password')->post('/reset-password', $post())
            ->assertSessionHasErrors(['new_password' => 'Too many attempts. Please try again later.']);

        expect(session('password_reset.token'))->toBe(RESET_TOKEN);
    });
});

describe('logging', function () {
    it('never writes the token, the password or the e-mail into api_logs', function () {
        T::fake([
            'POST vendor/password/forgot' => Http::response(['message' => NEUTRAL_MESSAGE]),
            'POST vendor/password/reset' => Http::response(['message' => 'ok']),
        ]);

        $this->post('/forgot-password', ['email' => 'sales@acme.test']);
        $this->withSession(['password_reset.token' => RESET_TOKEN])->post('/reset-password', [
            'new_password' => 'Brand#New-Pass9', 'new_password_confirmation' => 'Brand#New-Pass9',
        ]);

        $logs = ApiLog::query()->where('url', 'like', '%password/%')->get();
        expect($logs)->toHaveCount(2);
        foreach ($logs as $log) {
            $dump = json_encode($log->getAttributes());
            expect($dump)->not->toContain(RESET_TOKEN)->not->toContain('Brand#New-Pass9')->not->toContain('sales@acme.test');
        }
    });
});
