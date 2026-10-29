<?php

use App\Models\ApiLog;
use App\Services\AdminApi\AdminSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Rsa;
use Tests\Support\TotFixtures as T;

uses(RefreshDatabase::class);

const SECOND_TOT = 'SECONDTOT0000002';

function rvRow(string $id, string $fy, string $type = 'new', string $status = 'pending_vendor', string $label = 'Pending with vendor', bool $editable = true): array
{
    return ['id' => $id, 'tot_number' => 5, 'financial_year' => $fy, 'type' => $type, 'vendor_name' => 'Acme Foods Pvt Ltd', 'status' => $status, 'status_label' => $label, 'editable' => $editable, 'submitted_at' => null];
}

/** Login answer for a returning vendor: no link, so `tot` is null and `tots` lists every TOT. */
function rvLogin(array $tots, bool $mustChange = false, ?array $tot = null): array
{
    return [
        'access_token' => T::TOKEN, 'token_type' => 'Bearer', 'expires_in' => 1800,
        'user' => ['id' => 'USERPUBLICID0001', 'name' => 'Acme', 'email' => 'sales@acme.test', 'must_change_password' => $mustChange],
        'tot' => $tot, 'tots' => $tots,
    ];
}

function rvFake(array $login, array $routes = []): void
{
    T::fake($routes + [
        'POST vendor/login' => Http::response($login),
        'GET tots' => Http::response(['data' => $login['tots']]),
        'GET tots/'.SECOND_TOT => Http::response(T::detail(['tot' => ['id' => SECOND_TOT, 'financial_year' => '2025-26', 'status_label' => 'TOT Approved', 'editable' => false]])),
    ]);
}

function rvLoginPost(string $email = 'sales@acme.test', string $password = 'Own#Pass-123')
{
    return test()->post('/login', ['email' => $email, 'password' => $password]);
}

beforeEach(function () {
    $this->keys = Rsa::pair();
    config(['admin_api.rsa_public_key_path' => $this->keys['publicPath']]);
});

describe('the login page', function () {
    it('is a plain e-mail and password form that posts to /login, with CSRF', function () {
        $html = $this->get('/login')->assertOk()->getContent();

        expect($html)->toMatch('/<form method="POST" action="[^"]+\/login"/')->toContain('name="_token"')
            ->toContain('name="email"')->toContain('type="password"')->toContain('autocomplete="current-password"')
            ->toContain('invitation email')->not->toContain('link_token');
    });

    it('leaves remembering the password to the browser or password manager: standard field types and autocomplete, no cookie of ours', function () {
        $response = $this->get('/login')->assertOk();
        $html = $response->getContent();

        expect($html)->toMatch('/<input id="email" name="email" type="email"[^>]*autocomplete="username"/')
            ->toMatch('/<input id="password" name="password" type="password"[^>]*autocomplete="current-password"/')
            ->toMatch('/<form method="POST" action="[^"]+\/login" autocomplete="on"/')
            ->not->toContain('name="remember"');
        expect(collect($response->headers->getCookies())->map(fn ($c) => $c->getName())->all())->not->toContain('vob_remember_login')->not->toContain('vob_remember_email');
    });

    it('does not store the password or the e-mail in any cookie after signing in', function () {
        rvFake(rvLogin([rvRow(T::TOT_ID, '2026-27')]));

        $response = test()->post('/login', ['email' => 'sales@acme.test', 'password' => 'Own#Pass-123', 'remember' => '1']);

        $names = collect($response->headers->getCookies())->map(fn ($c) => $c->getName())->all();
        expect($names)->not->toContain('vob_remember_login')->not->toContain('vob_remember_email');
    });

    it('needs no invitation link and is linked from the landing page', function () {
        $this->get('/')->assertOk()->assertSee(route('login'), false)->assertSee('Already registered?');
        $this->get('/login')->assertOk();
    });

    it('sends an already signed-in vendor on to the form', function () {
        T::fake();

        $this->withSession(T::session())->get('/login')->assertRedirect(route('home'));
    });

    it('sends someone who is not signed in and has no link to it, from any page', function () {
        $this->get('/home')->assertRedirect(route('login'));
        $this->get('/tots')->assertRedirect(route('login'));
        $this->get('/sign-in')->assertRedirect(route('login'));
    });

    it('serves the page with the usual security headers', function () {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Cache-Control', 'no-store, private');
        expect($response->getContent())->not->toMatch('/<script(?![^>]*\ssrc=)/i')->not->toMatch('/\sstyle="/i');
    });
});

describe('signing in without the link', function () {
    it('calls POST /vendor/login with the credentials only in X-Auth-Data and no link_token', function () {
        rvFake(rvLogin([rvRow(T::TOT_ID, '2026-27')]));

        rvLoginPost();

        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/vendor/login')) {
                return false;
            }
            $envelope = json_decode(base64_decode(strtr($r->header('X-Auth-Data')[0], '-_', '+/')), true);
            openssl_private_decrypt(base64_decode($envelope['ek']), $aesKey, $this->keys['privatePem'], OPENSSL_PKCS1_OAEP_PADDING);
            $fields = json_decode(openssl_decrypt(base64_decode($envelope['ct']), 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, base64_decode($envelope['iv']), base64_decode($envelope['tag'])), true);

            return $fields['email'] === 'sales@acme.test' && $fields['password'] === 'Own#Pass-123'
                && ! array_key_exists('link_token', $fields)                               // the whole point of the page
                && array_keys(json_decode($r->body(), true)) === ['username', 'password']  // decoy body
                && ! str_contains($r->body(), 'Own#Pass-123') && ! $r->hasHeader('Authorization') && $r->hasHeader('X-Signature');
        });
    });

    it('keeps the token server-side and the password out of the logs', function () {
        rvFake(rvLogin([rvRow(T::TOT_ID, '2026-27')]));

        $response = rvLoginPost();

        expect(app(AdminSession::class)->token())->toBe(T::TOKEN)
            ->and(json_encode($response->headers->all()))->not->toContain(T::TOKEN)->not->toContain('Own#Pass-123');
        expect(ApiLog::all()->toJson())->not->toContain(T::TOKEN)->not->toContain('Own#Pass-123');
    });

    it('validates the input before calling the Admin', function () {
        Http::fake();

        $this->post('/login', ['email' => 'nope', 'password' => ''])->assertSessionHasErrors(['email', 'password']);
        Http::assertNothingSent();
    });

    it('gives one message for every failure and does not say which part was wrong', function (int $status, string $expected) {
        rvFake(rvLogin([]), ['POST vendor/login' => Http::response(['error' => 'x'], $status)]);

        $this->from('/login')->post('/login', ['email' => 'sales@acme.test', 'password' => 'Wrong#Pass1'])
            ->assertRedirect('/login')->assertSessionHasErrors(['email' => $expected])->assertSessionHasInput('email', 'sales@acme.test')->assertSessionMissing('_old_input.password');

        expect(app(AdminSession::class)->check())->toBeFalse();
    })->with([
        'wrong / unknown / inactive / internal account' => [401, 'The email or password is not correct.'],
        'too many attempts' => [429, 'Too many unsuccessful attempts. Please wait 15 minutes and try again.'],
        'request rejected (bad / stale envelope)' => [404, 'We could not sign you in right now. Please try again later.'],
        'link_invalid does not exist here' => [422, 'We could not sign you in right now. Please try again later.'],
    ]);

    it('limits attempts per IP', function () {
        rvFake(rvLogin([]), ['POST vendor/login' => Http::response(['error' => 'invalid_credentials'], 401)]);

        foreach (range(1, 10) as $i) {
            $this->post('/login', ['email' => 'sales@acme.test', 'password' => 'Wrong#Pass'.$i])->assertStatus(302);
        }
        $this->post('/login', ['email' => 'sales@acme.test', 'password' => 'Wrong#Pass11'])->assertStatus(429);
    });

    it('is POST only', function () {
        $this->get('/login')->assertOk();
        $this->put('/login')->assertStatus(405);
    });
});

describe('after signing in: choosing the Terms of Trade', function () {
    it('opens the only Terms of Trade straight away', function () {
        rvFake(rvLogin([rvRow(T::TOT_ID, '2026-27')]));

        rvLoginPost()->assertRedirect(route('home'));

        $this->get('/home')->assertRedirect(route('home')); // picks the only TOT
        $html = $this->get('/home')->assertOk()->getContent();
        expect($html)->toContain('Business Development Agreement')->toContain('Terms of Trade 2026-27')->toContain('class="tracker"');
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/tots/'.T::TOT_ID) && $r->hasHeader('Authorization', 'Bearer '.T::TOKEN));
    });

    it('says "No Terms of Trade yet" when the vendor has none', function () {
        rvFake(rvLogin([]));

        rvLoginPost()->assertRedirect(route('home'));
        $this->get('/home')->assertOk()->assertSee('No Terms of Trade yet. Please contact your Lulu buyer.');
        $this->get('/tots')->assertOk()->assertSee('No Terms of Trade yet. Please contact your Lulu buyer.');
    });

    it('lists several, newest first, with year, type, status and whether they can be edited', function () {
        rvFake(rvLogin([
            rvRow(T::TOT_ID, '2026-27', 'renew'),
            rvRow(SECOND_TOT, '2025-26', 'new', 'approved', 'TOT Approved', false),
        ]));

        rvLoginPost()->assertRedirect(route('home'));
        $this->get('/home')->assertRedirect(route('tots.index'));
        $html = $this->get('/tots')->assertOk()->getContent();

        expect($html)->toContain('My Terms of Trade')->toContain('2026-27')->toContain('Renewal')->toContain('Pending with vendor')->toContain('2025-26')->toContain('TOT Approved')
            ->toContain('Read only')
            ->and(strpos($html, '2026-27'))->toBeLessThan(strpos($html, '2025-26'))
            ->and($html)->toMatch('/<button type="submit" class="row-open">Open<\/button>/')->toMatch('/<button type="submit" class="row-open">View<\/button>/');
    });

    it('never puts a TOT id in the markup or a URL: rows are chosen by position, with a POST', function () {
        rvFake(rvLogin([rvRow(T::TOT_ID, '2026-27'), rvRow(SECOND_TOT, '2025-26')]));
        rvLoginPost();

        $html = $this->get('/tots')->getContent();

        expect($html)->not->toContain(T::TOT_ID)->not->toContain(SECOND_TOT)
            ->and($html)->toMatch('/<form method="POST" action="[^"]+\/tots\/select"/')->toContain('name="index" value="0"')->toContain('name="index" value="1"');
    });

    it('opens the chosen one and works on it from then on', function () {
        rvFake(rvLogin([rvRow(T::TOT_ID, '2026-27'), rvRow(SECOND_TOT, '2025-26', 'new', 'approved', 'TOT Approved', false)]));
        rvLoginPost();
        $this->get('/tots');

        $this->post('/tots/select', ['index' => 1])->assertRedirect(route('home'));
        $html = $this->get('/home')->assertOk()->getContent();

        // the approved TOT opens read only: tracker kept, no save / submit buttons
        expect($html)->toContain('Terms of Trade 2025-26')->not->toContain('Save Draft')->not->toContain('data-submit')->toContain('class="tracker"');
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/tots/'.SECOND_TOT));
    });

    it('forgets what belonged to the previous TOT when switching', function () {
        rvFake(rvLogin([rvRow(T::TOT_ID, '2026-27'), rvRow(SECOND_TOT, '2025-26')]));
        rvLoginPost();
        $this->withSession(['tot.locked' => ['company_codes'], 'cashfree.pending' => 'vob123']);
        $this->get('/tots');

        $this->post('/tots/select', ['index' => 0]);

        expect(session('tot.locked'))->toBeNull()->and(session('cashfree.pending'))->toBeNull();
    });

    it('rejects a position that is not in the list, and anything that is not a number', function () {
        rvFake(rvLogin([rvRow(T::TOT_ID, '2026-27'), rvRow(SECOND_TOT, '2025-26')]));
        rvLoginPost();
        $this->get('/tots');

        $this->from('/tots')->post('/tots/select', ['index' => 7])->assertRedirect(route('tots.index'))->assertSessionHasErrors('index');
        $this->from('/tots')->post('/tots/select', ['index' => 'abc'])->assertSessionHasErrors('index');
        $this->from('/tots')->post('/tots/select', [])->assertSessionHasErrors('index');
        $this->from('/tots')->post('/tots/select', ['index' => T::TOT_ID])->assertSessionHasErrors('index'); // an id is not accepted
    });

    it('shows fresh statuses from GET /tots, and falls back to the login answer when that fails', function () {
        rvFake(rvLogin([rvRow(T::TOT_ID, '2026-27'), rvRow(SECOND_TOT, '2025-26')]), ['GET tots' => Http::response(['data' => [
            rvRow(T::TOT_ID, '2026-27', 'new', 'pending_buyer', 'Pending with buyer', false), rvRow(SECOND_TOT, '2025-26'),
        ]])]);
        rvLoginPost();

        expect($this->get('/tots')->getContent())->toContain('Pending with buyer');

        rvFake(rvLogin([rvRow(T::TOT_ID, '2026-27'), rvRow(SECOND_TOT, '2025-26')]), ['GET tots' => Http::response([], 500)]);
        $this->withSession(T::session() + ['admin_api.tots' => [rvRow(T::TOT_ID, '2026-27'), rvRow(SECOND_TOT, '2025-26')]]);
        expect($this->get('/tots')->assertOk()->getContent())->toContain('Pending with vendor');
    });

    it('is only for signed-in vendors and the switch is a POST', function () {
        $this->get('/tots')->assertRedirect(route('login'));
        $this->post('/tots/select', ['index' => 0])->assertRedirect(route('login'));
        $this->get('/tots/select')->assertStatus(405);
    });

    it('offers "My Terms of Trade" in the header only when there is more than one', function () {
        rvFake(rvLogin([rvRow(T::TOT_ID, '2026-27')]));
        rvLoginPost();
        $this->get('/home');
        expect($this->get('/home')->getContent())->not->toContain('My Terms of Trade');

        rvFake(rvLogin([rvRow(T::TOT_ID, '2026-27'), rvRow(SECOND_TOT, '2025-26')]));
        rvLoginPost();
        $this->post('/tots/select', ['index' => 0]);
        expect($this->get('/home')->getContent())->toContain('class="header-link"')->toContain('My Terms of Trade');
    });

    it('sends a vendor with a temporary password to the change-password page first, then to the choice', function () {
        rvFake(rvLogin([rvRow(T::TOT_ID, '2026-27'), rvRow(SECOND_TOT, '2025-26')], mustChange: true), ['POST vendor/password' => Http::response(['message' => 'ok'])]);

        rvLoginPost('sales@acme.test', 'Temp#Pass1')->assertRedirect(route('password.edit'));
        $this->get('/tots')->assertRedirect(route('password.edit'));  // nothing else is reachable
        $this->get('/home')->assertRedirect(route('password.edit'));

        $this->post('/password', ['current_password' => 'Temp#Pass1', 'new_password' => 'Brand#New-Pass9', 'new_password_confirmation' => 'Brand#New-Pass9'])
            ->assertRedirect(route('home'));
        $this->get('/home')->assertRedirect(route('tots.index'));
    });

    it('leaves the link sign-in as it was: the linked TOT opens directly even when there are several', function () {
        rvFake(rvLogin([rvRow(T::TOT_ID, '2026-27'), rvRow(SECOND_TOT, '2025-26')], tot: rvRow(T::TOT_ID, '2026-27')));

        $this->withSession(['admin_api.link' => ['token' => 'LINK-TOKEN-VALUE-0123', 'vendor_name' => 'Acme', 'email_hint' => 'a***@b.com', 'financial_year' => '2026-27', 'type' => 'new', 'first_time_login' => false]])
            ->post('/sign-in', ['email' => 'sales@acme.test', 'password' => 'Own#Pass-123'])->assertRedirect(route('home'));

        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/vendor/login')) {
                return false;
            }
            $envelope = json_decode(base64_decode(strtr($r->header('X-Auth-Data')[0], '-_', '+/')), true);
            openssl_private_decrypt(base64_decode($envelope['ek']), $aesKey, $this->keys['privatePem'], OPENSSL_PKCS1_OAEP_PADDING);
            $fields = json_decode(openssl_decrypt(base64_decode($envelope['ct']), 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, base64_decode($envelope['iv']), base64_decode($envelope['tag'])), true);

            return $fields['link_token'] === 'LINK-TOKEN-VALUE-0123';
        });
        $this->get('/home')->assertOk(); // no redirect to a list
        expect(app(AdminSession::class)->tots())->toHaveCount(2); // but the list is there for "My Terms of Trade"
    });
});

describe('when the session ends', function () {
    it('sends a vendor who signed in without a link back to the login page', function () {
        rvFake(rvLogin([rvRow(T::TOT_ID, '2026-27')]), ['GET tots/'.T::TOT_ID => Http::response(['error' => 'unauthenticated'], 401)]);
        rvLoginPost();
        $this->get('/home'); // selects the only TOT

        $this->get('/home')->assertRedirect(route('login'))->assertSessionHas('status', 'Your session has ended. Please sign in again.');
        expect(app(AdminSession::class)->check())->toBeFalse();
    });

    it('does so after the idle time too, with JSON for the autosave', function () {
        config(['vob.session_idle_minutes' => 10]);
        T::fake();
        $expired = T::session();
        $expired['admin_api.last_seen'] = time() - 11 * 60;
        unset($expired['admin_api.link']);

        $this->withSession($expired)->get('/home')->assertRedirect(route('login'));
        $this->flushSession()->withSession($expired)->postJson('/tot/autosave', ['form' => []])->assertStatus(401);
    });

    it('still sends a vendor who came by link back to the link sign-in', function () {
        T::fake(['GET tots/'.T::TOT_ID => Http::response(['error' => 'unauthenticated'], 401)]);

        $this->withSession(T::session())->get('/home')->assertRedirect(route('sign-in'));
    });

    it('signs out to the landing page and then asks for the login page', function () {
        rvFake(rvLogin([rvRow(T::TOT_ID, '2026-27')]), ['POST vendor/logout' => Http::response(['message' => 'bye'])]);
        rvLoginPost();

        $this->post('/logout')->assertRedirect(route('login'));
        $this->get('/home')->assertRedirect(route('login'));
    });
});
