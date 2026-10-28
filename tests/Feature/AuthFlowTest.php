<?php

use App\Models\ApiLog;
use App\Services\AdminApi\AdminSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Rsa;

uses(RefreshDatabase::class);

const LINK = 'ENCRYPTED-LINK-TOKEN-abcdefghij==';
const ACCESS = 'ACCESS-TOKEN-0123456789-0123456789-0123456789-0123456789-abcd';

function resolved(array $over = []): array
{
    return $over + ['valid' => true, 'financial_year' => '2026-27', 'type' => 'new', 'vendor_name' => 'Acme Foods Pvt Ltd', 'email_hint' => 's***@a***.com', 'first_time_login' => true];
}

function loginResponse(bool $mustChange = true): array
{
    return [
        'access_token' => ACCESS, 'token_type' => 'Bearer', 'expires_in' => 1800,
        'user' => ['id' => 'USERPUBLICID0001', 'name' => 'Acme', 'email' => 'sales@acme.test', 'must_change_password' => $mustChange],
        'tot' => ['id' => 'TOTPUBLICID00001', 'tot_number' => 12, 'financial_year' => '2026-27', 'type' => 'new', 'vendor_name' => 'Acme Foods Pvt Ltd', 'status' => 'pending_vendor', 'status_label' => 'Pending with vendor', 'editable' => true],
    ];
}

function totDetail(): array
{
    return [
        'tot' => ['id' => 'TOTPUBLICID00001', 'financial_year' => '2026-27', 'vendor_name' => 'Acme Foods Pvt Ltd', 'status' => 'pending_vendor', 'status_label' => 'Pending with vendor', 'submitted_at' => '2026-10-20T08:00:00+00:00'],
        'draft_saved_at' => '2026-10-20T05:12:00+00:00',
        'progress' => ['current' => 'vendor', 'steps' => [
            ['key' => 'initiated', 'label' => 'TOT initiated', 'state' => 'done', 'entered_at' => '2026-10-20T09:00:00+00:00'],
            ['key' => 'vendor', 'label' => 'Vendor details & submission', 'state' => 'current', 'entered_at' => null],
            ['key' => 'buyer', 'label' => 'Buyer review', 'state' => 'pending', 'entered_at' => null],
        ]],
        'reviewer_comments' => [['status' => 'pending_vendor', 'comments' => 'Please fix the GST number', 'by' => 'Buyer One', 'at' => '2026-10-21']],
    ];
}

/** A browser session that has opened the link and (optionally) signed in. */
function linkSession(): array
{
    return ['admin_api.link' => ['token' => LINK, 'vendor_name' => 'Acme Foods Pvt Ltd', 'email_hint' => 's***@a***.com', 'financial_year' => '2026-27', 'type' => 'new', 'first_time_login' => true]];
}

function signedInSession(bool $mustChange = false): array
{
    return linkSession() + [
        'admin_api.token' => ACCESS,
        'admin_api.user' => ['id' => 'USERPUBLICID0001', 'name' => 'Acme', 'email' => 'sales@acme.test', 'must_change_password' => $mustChange],
        'admin_api.tot' => loginResponse()['tot'],
    ];
}

beforeEach(function () {
    $keys = Rsa::pair();
    config(['admin_api.rsa_public_key_path' => $keys['publicPath']]);
});

describe('invitation link', function () {
    it('resolves the token with the Admin, keeps it server-side and moves to sign-in', function () {
        Http::fake(['admin.test/*' => Http::response(resolved())]);

        $this->get('/onboard/'.LINK)->assertRedirect(route('sign-in'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/links/resolve') && $r['token'] === LINK && $r->hasHeader('X-Signature'));
        expect(app(AdminSession::class)->link()['token'])->toBe(LINK);

        $this->get('/sign-in')->assertOk()
            ->assertSee('Acme Foods Pvt Ltd')->assertSee('s***@a***.com')->assertSee('2026-27')
            ->assertSee('temporary password from your email')
            ->assertDontSee(LINK);
    });

    it('shows the existing-vendor wording when it is not a first login', function () {
        Http::fake(['admin.test/*' => Http::response(resolved(['first_time_login' => false, 'type' => 'renew']))]);

        $this->get('/onboard/'.LINK);
        $this->get('/sign-in')->assertSee('Enter your Vendor Handshake password')->assertSee('(renewal)')->assertDontSee('temporary password');
    });

    it('shows "ask your Lulu contact" for an invalid link', function () {
        Http::fake(['admin.test/*' => Http::response(['valid' => false, 'error' => 'link_invalid'], 422)]);

        $this->get('/onboard/'.LINK)->assertStatus(410)->assertSee('ask your Lulu contact to resend the invitation')->assertSee('newer invitation email');
        expect(app(AdminSession::class)->link())->toBeNull();
    });

    it('shows a neutral page when the Admin rejects the request (404) or is down', function (int $status) {
        Http::fake(['admin.test/*' => Http::response([], $status)]);

        $this->get('/onboard/'.LINK)->assertStatus(503)->assertSee('Temporarily unavailable')->assertDontSee('404');
    })->with([404, 500]);

    it('shows a neutral page when the Admin cannot be reached or is not configured', function () {
        Http::fake(fn () => throw new ConnectionException('boom'));
        $this->get('/onboard/'.LINK)->assertStatus(503)->assertDontSee('boom');

        config(['admin_api.hmac_secret' => null]);
        $this->get('/onboard/'.LINK)->assertStatus(503)->assertDontSee('HMAC');
    });

    it('sends someone without a link back to the landing page', function () {
        $this->get('/sign-in')->assertRedirect(route('login'));
        $this->post('/sign-in', ['email' => 'a@b.test', 'password' => 'x'])->assertRedirect(route('login'));
    });
});

describe('sign-in', function () {
    it('signs in, keeps the token out of the browser and sends a first-time vendor to the password page', function () {
        Http::fake(['admin.test/*' => Http::response(loginResponse())]);

        $response = $this->withSession(linkSession())->post('/sign-in', ['email' => 'sales@acme.test', 'password' => 'Temp#Pass1']);

        $response->assertRedirect(route('password.edit'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/vendor/login')
            && $r->hasHeader('X-Auth-Data')
            && ! str_contains($r->body(), 'Temp#Pass1') && ! str_contains($r->body(), 'sales@acme.test'));

        expect(app(AdminSession::class)->token())->toBe(ACCESS);
        // nothing sensitive is ever sent to the browser
        expect($response->headers->get('Location').json_encode($response->headers->all()))->not->toContain(ACCESS)->not->toContain('Temp#Pass1');
        // ...nor stored in the log
        expect(ApiLog::all()->toJson())->not->toContain(ACCESS)->not->toContain('Temp#Pass1')->not->toContain(LINK);
    });

    it('goes straight to the home page when no password change is needed', function () {
        Http::fake(['admin.test/*' => Http::response(loginResponse(mustChange: false))]);

        $this->withSession(linkSession())->post('/sign-in', ['email' => 'sales@acme.test', 'password' => 'Own#Pass-123'])
            ->assertRedirect(route('home'));
    });

    it('passes the link token to the Admin inside the encrypted header only', function () {
        Http::fake(['admin.test/*' => Http::response(loginResponse())]);

        $this->withSession(linkSession())->post('/sign-in', ['email' => 'sales@acme.test', 'password' => 'Temp#Pass1']);

        Http::assertSent(fn (Request $r) => ! str_contains($r->body(), LINK) && ! str_contains($r->url(), LINK));
    });

    it('shows one generic message for failures and does not echo the password', function (int $status, string $expected) {
        Http::fake(['admin.test/*' => Http::response(['error' => 'x'], $status)]);

        $this->withSession(linkSession())->from('/sign-in')
            ->post('/sign-in', ['email' => 'sales@acme.test', 'password' => 'Wrong#Pass1'])
            ->assertRedirect('/sign-in')->assertSessionHasErrors(['email' => $expected])
            ->assertSessionHasInput('email', 'sales@acme.test')->assertSessionMissing('_old_input.password');

        expect(app(AdminSession::class)->check())->toBeFalse();
    })->with([
        'wrong credentials' => [401, 'The email or password is not correct.'],
        'locked out' => [429, 'Too many unsuccessful attempts. Please wait 15 minutes and try again.'],
        'request rejected' => [404, 'We could not sign you in right now. Please try again later.'],
    ]);

    it('drops the link and says it is invalid when the Admin returns link_invalid', function () {
        Http::fake(['admin.test/*' => Http::response(['error' => 'link_invalid'], 422)]);

        $this->withSession(linkSession())->post('/sign-in', ['email' => 'sales@acme.test', 'password' => 'Temp#Pass1'])
            ->assertRedirect(route('link.invalid'));
        expect(app(AdminSession::class)->link())->toBeNull();
    });

    it('validates the input before calling the Admin', function () {
        Http::fake();

        $this->withSession(linkSession())->post('/sign-in', ['email' => 'not-an-email', 'password' => ''])
            ->assertSessionHasErrors(['email', 'password']);
        Http::assertNothingSent();
    });

    it('leaves account lockout to the Admin: the same email can try more than 5 times, but one IP is limited per minute', function () {
        Http::fake(['admin.test/*' => Http::response(['error' => 'invalid_credentials'], 401)]);

        foreach (range(1, 10) as $i) {
            $this->withSession(linkSession())->post('/sign-in', ['email' => 'sales@acme.test', 'password' => 'Wrong#Pass'.$i])->assertStatus(302);
        }
        $this->withSession(linkSession())->post('/sign-in', ['email' => 'other@acme.test', 'password' => 'Wrong#Pass9'])
            ->assertStatus(429)->assertSee('Too many attempts');
        Http::assertSentCount(10);
    });
});

describe('forced password change', function () {
    it('keeps a vendor who must change the password on the password page', function () {
        Http::fake();

        $this->withSession(signedInSession(mustChange: true))->get('/home')->assertRedirect(route('password.edit'));
        $this->get('/password')->assertOk()->assertSee('Choose a new password');
        Http::assertNothingSent();
    });

    it('requires a signed-in vendor', function () {
        $this->get('/home')->assertRedirect(route('login'));
        $this->get('/password')->assertRedirect(route('login'));
    });

    it('rejects weak or unchanged passwords locally, without calling the Admin', function (string $new, ?string $confirm) {
        Http::fake();

        $this->withSession(signedInSession(true))->from('/password')->post('/password', [
            'current_password' => 'Temp#Pass1', 'new_password' => $new, 'new_password_confirmation' => $confirm ?? $new,
        ])->assertSessionHasErrors('new_password');
        Http::assertNothingSent();
    })->with([
        'too short' => ['Ab#1xyz', null],
        'no symbol' => ['Abcdefghij12', null],
        'no number' => ['Abcdefghij#k', null],
        'no upper case' => ['abcdefghij#1', null],
        'no lower case' => ['ABCDEFGHIJ#1', null],
        'same as current' => ['Temp#Pass1', null],
        'confirmation differs' => ['Brand#New-Pass9', 'Brand#New-Pass8'],
    ]);

    it('changes the password through X-Auth-Data and continues to the home page', function () {
        Http::fake(['admin.test/*' => Http::response(['message' => 'ok'])]);

        $this->withSession(signedInSession(true))->post('/password', [
            'current_password' => 'Temp#Pass1', 'new_password' => 'Brand#New-Pass9', 'new_password_confirmation' => 'Brand#New-Pass9',
        ])->assertRedirect(route('home'))->assertSessionHas('status', 'Your password has been changed.');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/vendor/password')
            && $r->hasHeader('Authorization', 'Bearer '.ACCESS) && $r->hasHeader('X-Auth-Data')
            && ! str_contains($r->body(), 'Brand#New-Pass9') && ! str_contains($r->body(), 'Temp#Pass1'));
        expect(app(AdminSession::class)->mustChangePassword())->toBeFalse();
        expect(ApiLog::all()->toJson())->not->toContain('Brand#New-Pass9');
    });

    it('shows the Admin validation errors', function () {
        Http::fake(['admin.test/*' => Http::response(['errors' => ['current_password' => ['The current password is incorrect.']]], 422)]);

        $this->withSession(signedInSession(true))->from('/password')->post('/password', [
            'current_password' => 'Wrong#Pass1', 'new_password' => 'Brand#New-Pass9', 'new_password_confirmation' => 'Brand#New-Pass9',
        ])->assertSessionHasErrors(['current_password' => 'The current password is incorrect.']);
    });
});

describe('session expiry settings', function () {
    it('ends the session after the configured idle time and keeps the invitation link', function () {
        config(['vob.session_idle_minutes' => 10]);
        Http::fake(['admin.test/*' => Http::response(totDetail())]);

        $this->withSession(signedInSession() + ['admin_api.started_at' => time() - 3600, 'admin_api.last_seen' => time() - 11 * 60])
            ->get('/home')->assertRedirect(route('sign-in'))->assertSessionHas('status', 'Your session has ended. Please sign in again.');

        expect(app(AdminSession::class)->check())->toBeFalse()->and(app(AdminSession::class)->link())->not->toBeNull();
        Http::assertNothingSent();
    });

    it('keeps an active vendor signed in and refreshes the idle clock', function () {
        config(['vob.session_idle_minutes' => 10]);
        Http::fake(['admin.test/*' => Http::response(totDetail())]);

        $this->withSession(signedInSession() + ['admin_api.started_at' => time() - 3600, 'admin_api.last_seen' => time() - 9 * 60])
            ->get('/home')->assertOk();

        expect(time() - session('admin_api.last_seen'))->toBeLessThan(5);
    });

    it('ends the session after the absolute limit even if the vendor is active', function () {
        config(['vob.session_absolute_minutes' => 60]);
        Http::fake();

        $this->withSession(signedInSession() + ['admin_api.started_at' => time() - 61 * 60, 'admin_api.last_seen' => time()])
            ->get('/home')->assertRedirect(route('sign-in'));
    });

    it('stamps both clocks at sign-in', function () {
        Http::fake(['admin.test/*' => Http::response(loginResponse())]);

        $this->withSession(linkSession())->post('/sign-in', ['email' => 'sales@acme.test', 'password' => 'Temp#Pass1']);

        expect(time() - session('admin_api.started_at'))->toBeLessThan(5)->and(time() - session('admin_api.last_seen'))->toBeLessThan(5);
    });

    it('has defaults of 30 minutes idle and 8 hours absolute', function () {
        expect(config('vob.session_idle_minutes'))->toBe(30)->and(config('vob.session_absolute_minutes'))->toBe(480);
    });
});

describe('after sign-in', function () {
    it('shows the home page with the tracker, draft time and reviewer comments from the API', function () {
        Http::fake(['admin.test/*' => Http::response(totDetail())]);

        $this->withSession(signedInSession())->get('/home')->assertOk()
            ->assertSee('class="tracker"', false)->assertSee('Vendor Submission', false)
            ->assertSee('Draft saved 20-Oct-2026, 10:42 AM')->assertSee('Pending with vendor')
            ->assertSee('Please fix the GST number')->assertSee('Terms of Trade 2026-27')
            ->assertDontSee(ACCESS);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://admin.test/api/v1/tots/TOTPUBLICID00001' && $r->hasHeader('Authorization', 'Bearer '.ACCESS));
    });

    it('sends the vendor back to sign-in when the Admin session has expired, keeping the link', function () {
        Http::fake(['admin.test/*' => Http::response(['error' => 'unauthenticated'], 401)]);

        $this->withSession(signedInSession())->get('/home')
            ->assertRedirect(route('sign-in'))->assertSessionHas('status', 'Your session has ended. Please sign in again.');

        expect(app(AdminSession::class)->check())->toBeFalse()
            ->and(app(AdminSession::class)->link()['token'])->toBe(LINK);
    });

    it('shows a neutral page if the TOT cannot be loaded', function () {
        Http::fake(['admin.test/*' => Http::response(['error' => 'x'], 404)]);

        $this->withSession(signedInSession())->get('/home')->assertStatus(503);
    });

    it('signs out: ends the Admin session and wipes the browser session', function () {
        Http::fake(['admin.test/*' => Http::response(['message' => 'bye'])]);

        $this->withSession(signedInSession())->post('/logout')->assertRedirect(route('login'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/vendor/logout') && $r->hasHeader('Authorization', 'Bearer '.ACCESS));
        expect(app(AdminSession::class)->check())->toBeFalse()->and(app(AdminSession::class)->link())->toBeNull();
        $this->get('/home')->assertRedirect(route('login'));
    });

    it('still signs out locally when the Admin is unreachable', function () {
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->withSession(signedInSession())->post('/logout')->assertRedirect(route('login'));
        expect(app(AdminSession::class)->check())->toBeFalse();
    });

    it('never puts ids, emails or tokens in a URL', function () {
        Http::fake(['admin.test/*' => Http::response(totDetail())]);

        $html = $this->withSession(signedInSession())->get('/home')->getContent();

        expect($html)->not->toContain('TOTPUBLICID00001')->not->toContain('sales@acme.test')->not->toContain('USERPUBLICID0001');
    });
});

it('uses POST for every browser action that changes state (no PUT, PATCH or DELETE routes)', function () {
    $stateChanging = collect(app('router')->getRoutes()->getRoutes())
        ->flatMap(fn ($route) => collect($route->methods())->reject(fn ($m) => in_array($m, ['GET', 'HEAD']))->map(fn ($m) => $m.' '.$route->uri()))
        ->values()->all();

    expect($stateChanging)->each->toStartWith('POST ');
});
