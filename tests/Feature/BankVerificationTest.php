<?php

use App\Models\ApiLog;
use App\Models\CashfreeVerification;
use App\Services\Cashfree\BankVerificationService;
use App\Services\Cashfree\WebhookSignature;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TotFixtures as T;

uses(RefreshDatabase::class);

const CF_SANDBOX = 'https://sandbox.cashfree.com/verification';

beforeEach(function () {
    // throwaway values, not real credentials
    config(['cashfree.client_id' => 'test-client-id', 'cashfree.client_secret' => 'test-client-secret', 'cashfree.env' => 'sandbox', 'cashfree.name_match' => 'ignore']);
});

function bankDetail(array $bank = [], array $tot = []): array
{
    return T::detail(['tot' => $tot, 'form' => ['bank' => $bank + ['account_name' => 'Acme Foods', 'account_no' => '026291800001191', 'ifsc' => 'YESB0000262']]]);
}

/** Fake Admin + Cashfree together. $cashfree maps "METHOD path" (after /verification/) to an answer. */
function fakeBoth(array $cashfree = [], array $admin = [], ?array $detail = null): void
{
    $adminRoutes = $admin + [
        'POST tots/'.T::TOT_ID.'/bank-verification' => Http::response(['saved' => true]),
    ];
    $cfDefaults = [
        'POST reverse-penny-drop' => Http::response([
            'verification_id' => 'echo', 'ref_id' => 3905, 'valid_upto' => now()->addMinutes(10)->toIso8601String(),
            'upi_link' => 'upi://pay?pa=success@upi&tr=3905', 'gpay' => 'tez://upi/pay?x=1', 'phonepe' => 'phonepe://pay?x=1', 'qr_code' => 'iVBORw0KGgoQRDATA',
        ]),
        'GET remitter/status' => Http::response(['status' => 'CREATED', 'ref_id' => '3905']),
    ];

    Http::fake(function (Request $request) use ($adminRoutes, $cashfree, $cfDefaults, $detail) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (str_contains($request->url(), 'cashfree.com')) {
            $key = $request->method().' '.ltrim(substr($path, strlen('/verification')), '/');
            $answer = $cashfree[$key] ?? $cfDefaults[$key] ?? Http::response(['type' => 'x', 'code' => 'unknown', 'message' => 'not faked'], 404);
        } else {
            $key = $request->method().' '.ltrim(substr($path, strlen('/api/v1')), '/');
            $answer = $adminRoutes[$key] ?? match ($key) {
                'GET tots/'.T::TOT_ID => Http::response($detail ?? bankDetail()),
                'GET masters' => Http::response(T::masters()),
                default => Http::response(['error' => 'not faked: '.$key], 404),
            };
        }

        return is_callable($answer) ? $answer($request) : $answer;
    });
}

function startVerification(): CashfreeVerification
{
    test()->withSession(T::session())->post('/bank/verify')->assertRedirect(route('bank.verify'));

    return CashfreeVerification::latest('id')->firstOrFail();
}

function paid(array $over = []): array
{
    return $over + ['status' => 'SUCCESS', 'ref_id' => '3905', 'bank_account' => '026291800001191', 'ifsc' => 'YESB0000262', 'upi' => 'someone@upi',
        'name_at_bank' => 'ACME FOODS', 'name_match_result' => 'DIRECT_MATCH', 'utr' => '33'];
}

describe('starting a verification', function () {
    it('creates a Cashfree request for the saved bank details and shows the QR page', function () {
        fakeBoth();
        $row = startVerification();

        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST' && $r->url() === CF_SANDBOX.'/reverse-penny-drop'
                && $r->hasHeader('x-client-id', 'test-client-id') && $r->hasHeader('x-client-secret', 'test-client-secret')
                && preg_match('/^vob[a-f0-9]{24}$/', $r['verification_id']) && $r['name'] === 'Acme Foods'
                && $r['redirect_url'] === route('home');
        });
        expect($row->status)->toBe('CREATED')->and($row->ref_id)->toBe('3905')->and($row->account_last4)->toBe('1191')
            ->and($row->tot_ref)->toBe(T::TOT_ID)->and($row->payload['upi_link'])->toStartWith('upi://');

        $html = $this->get(route('bank.verify'))->assertOk()->getContent();
        expect($html)->toContain('ending <strong>1191</strong>')->toContain('data:image/png;base64,iVBORw0KGgoQRDATA')
            ->toContain('href="upi://pay?pa=success@upi&amp;tr=3905"')->toContain('Google Pay')->toContain('PhonePe')
            ->not->toContain('026291800001191')->not->toContain($row->verification_id);
    });

    it('never keeps the account number: only the last four digits and a keyed hash', function () {
        fakeBoth();
        $row = startVerification();

        expect(json_encode(CashfreeVerification::query()->get()->makeVisible(['payload', 'expected_fingerprint'])->toArray()))->not->toContain('026291800001191');
        expect(DB::table('cashfree_verifications')->first()->payload)->not->toContain('iVBOR'); // encrypted at rest
        expect($row->expected_fingerprint)->toMatch('/^[a-f0-9]{64}$/');
    });

    it('asks the vendor to fill in the bank details first', function (array $bank) {
        fakeBoth(detail: bankDetail($bank));

        $this->withSession(T::session())->from('/home')->post('/bank/verify')
            ->assertSessionHasErrors(['bank_verify' => 'Enter the account name, account number and IFS code, then verify.']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'cashfree'));
    })->with([[['account_no' => null]], [['ifsc' => '']], [['account_name' => null]]]);

    it('asks for the full account number again when the Admin returned it masked (renewals)', function () {
        fakeBoth(detail: bankDetail(['account_no' => '**********1191']));

        $this->withSession(T::session())->from('/home')->post('/bank/verify')
            ->assertSessionHasErrors(['bank_verify' => 'Please re-enter the full account number, then verify.']);
    });

    it('refuses when the TOT is no longer editable', function () {
        fakeBoth(detail: bankDetail([], ['editable' => false]));

        $this->withSession(T::session())->from('/home')->post('/bank/verify')->assertSessionHasErrors('bank_verify');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'cashfree'));
    });

    it('shows a neutral message when Cashfree rejects the request (IP, credentials, balance, down)', function (int $status, array $body) {
        fakeBoth(['POST reverse-penny-drop' => Http::response($body, $status)]);

        $this->withSession(T::session())->from('/home')->post('/bank/verify')
            ->assertSessionHasErrors(['bank_verify' => 'Bank verification is not available right now. Please try again later.']);
        expect(CashfreeVerification::count())->toBe(0);
    })->with([
        'ip not whitelisted' => [403, ['type' => 'authentication_error', 'code' => 'ip_validation_failed', 'message' => 'IP not whitelisted']],
        'bad credentials' => [401, ['type' => 'authentication_error', 'code' => 'authentication_failed', 'message' => 'Invalid clientId']],
        'no balance' => [422, ['type' => 'validation_error', 'code' => 'insufficient_balance', 'message' => 'Insufficient balance']],
        'server error' => [500, ['type' => 'internal_error', 'code' => 'api_error', 'message' => 'x']],
    ]);

    it('tells the vendor to wait when Cashfree rate-limits', function () {
        fakeBoth(['POST reverse-penny-drop' => Http::response(['type' => 'rate_limit_error', 'code' => 'too_many_requests_per_ip', 'message' => 'x'], 429)]);

        $this->withSession(T::session())->from('/home')->post('/bank/verify')
            ->assertSessionHasErrors(['bank_verify' => 'Too many attempts. Please wait a minute and try again.']);
    });

    it('fails gracefully when Cashfree is unreachable or not configured', function () {
        Http::fake(fn (Request $r) => str_contains($r->url(), 'cashfree') ? throw new ConnectionException('down') : Http::response(bankDetail()));
        $this->withSession(T::session())->from('/home')->post('/bank/verify')->assertSessionHasErrors('bank_verify');

        config(['cashfree.client_secret' => null]);
        fakeBoth();
        $this->withSession(T::session())->from('/home')->post('/bank/verify')->assertSessionHasErrors('bank_verify');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'cashfree'));
    });

    it('uses the live endpoint in production mode', function () {
        config(['cashfree.env' => 'production']);
        fakeBoth();
        startVerification();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.cashfree.com/verification/reverse-penny-drop');
    });

    it('needs a signed-in vendor and POST, and no ids appear in the URLs', function () {
        $this->post('/bank/verify')->assertRedirect(route('sign-in'));
        $this->get('/bank/verify')->assertRedirect(route('sign-in'));
        expect(route('bank.verify').route('bank.status').route('bank.create'))->not->toContain('vob');
    });

    it('sends vendors without a pending verification back to the form', function () {
        $this->withSession(T::session())->get('/bank/verify')->assertRedirect(route('home'));
    });
});

describe('checking the result', function () {
    it('keeps waiting while the payment has not been made', function () {
        fakeBoth();
        startVerification();

        $this->postJson('/bank/verify/status')->assertOk()->assertJson(['state' => 'pending', 'done' => false]);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'bank-verification'));
    });

    it('reports VERIFIED to the Admin when the paying account matches', function () {
        fakeBoth(['GET remitter/status' => Http::response(paid())]);
        $row = startVerification();

        $this->postJson('/bank/verify/status')->assertOk()->assertJson(['state' => 'verified', 'done' => true, 'message' => 'Bank account verified.']);

        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST' && str_ends_with($r->url(), '/tots/'.T::TOT_ID.'/bank-verification')
                && $r['status'] === 'verified' && $r['reference'] === '3905' && $r->hasHeader('Authorization', 'Bearer '.T::TOKEN);
        });
        expect($row->fresh()->status)->toBe('SUCCESS')->and($row->fresh()->reported_status)->toBe('verified')->and($row->fresh()->name_match_result)->toBe('DIRECT_MATCH');
        $this->get('/home')->assertSee('Bank account verified.'); // flashed for the form page
    });

    it('queries Cashfree by our verification id', function () {
        fakeBoth(['GET remitter/status' => Http::response(paid())]);
        $row = startVerification();
        $this->postJson('/bank/verify/status');

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_starts_with($r->url(), CF_SANDBOX.'/remitter/status?verification_id='.$row->verification_id));
    });

    it('reports FAILED when the payment was made from a different account', function (array $over) {
        fakeBoth(['GET remitter/status' => Http::response(paid($over))]);
        $row = startVerification();

        $this->postJson('/bank/verify/status')->assertOk()->assertJson(['state' => 'failed', 'done' => true]);

        expect($row->fresh()->status)->toBe('MISMATCH')->and($row->fresh()->reported_status)->toBe('failed');
        expect(T::sent('POST', 'tots/'.T::TOT_ID.'/bank-verification'))->toBe(['status' => 'failed']);
    })->with([
        'other account number' => [['bank_account' => '999988887777']],
        'other IFSC' => [['ifsc' => 'HDFC0001234']],
    ]);

    it('tolerates spacing and case differences in the account number and IFSC', function () {
        fakeBoth(['GET remitter/status' => Http::response(paid(['bank_account' => '0262 9180 0001191', 'ifsc' => 'yesb0000262']))]);
        startVerification();

        $this->postJson('/bank/verify/status')->assertJson(['state' => 'verified']);
    });

    it('can also fail on a name that does not match when configured to', function () {
        config(['cashfree.name_match' => 'reject_no_match']);
        fakeBoth(['GET remitter/status' => Http::response(paid(['name_match_result' => 'NO_MATCH']))]);
        startVerification();

        $this->postJson('/bank/verify/status')->assertJson(['state' => 'failed']);
    });

    it('ignores the name match by default', function () {
        fakeBoth(['GET remitter/status' => Http::response(paid(['name_match_result' => 'NO_MATCH']))]);
        startVerification();

        $this->postJson('/bank/verify/status')->assertJson(['state' => 'verified']);
    });

    it('reports FAILED when Cashfree says the payment failed', function () {
        fakeBoth(['GET remitter/status' => Http::response(['status' => 'FAILURE', 'ref_id' => '3905'])]);
        startVerification();

        $this->postJson('/bank/verify/status')->assertJson(['state' => 'failed', 'done' => true]);
        expect(T::sent('POST', 'tots/'.T::TOT_ID.'/bank-verification'))->toBe(['status' => 'failed']);
    });

    it('tells the vendor to start again when the link expired, without telling the Admin it failed', function () {
        fakeBoth(['GET remitter/status' => Http::response(['status' => 'EXPIRED', 'ref_id' => '3905'])]);
        startVerification();

        $this->postJson('/bank/verify/status')->assertJson(['state' => 'expired', 'done' => true]);
        expect(T::sent('POST', 'tots/'.T::TOT_ID.'/bank-verification'))->toBeNull();
    });

    it('reports a result only once', function () {
        fakeBoth(['GET remitter/status' => Http::response(paid())]);
        $row = startVerification();

        $service = app(BankVerificationService::class);
        $service->settle($service->refresh($row));
        $service->settle($service->refresh($row->fresh()));

        $reports = collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/bank-verification'))->count();
        expect($reports)->toBe(1);
    });

    it('retries recording with the Admin on the next check if the Admin call failed', function () {
        $calls = 0;
        fakeBoth(['GET remitter/status' => Http::response(paid())], ['POST tots/'.T::TOT_ID.'/bank-verification' => function () use (&$calls) {
            return $calls++ === 0 ? Http::response([], 500) : Http::response(['saved' => true]);
        }]);
        $row = startVerification();

        $this->postJson('/bank/verify/status')->assertJson(['state' => 'pending', 'done' => false]);
        expect($row->fresh()->reported_status)->toBeNull();

        $this->postJson('/bank/verify/status')->assertJson(['state' => 'verified', 'done' => true]);
        expect($row->fresh()->reported_status)->toBe('verified');
    });

    it('does not count a 409 (TOT no longer editable) as an error', function () {
        fakeBoth(['GET remitter/status' => Http::response(paid())], ['POST tots/'.T::TOT_ID.'/bank-verification' => Http::response(['error' => 'not_editable'], 409)]);
        $row = startVerification();

        $this->postJson('/bank/verify/status')->assertJson(['state' => 'verified']);
        expect($row->fresh()->reported_status)->toBe('skipped');
    });

    it('only lets the vendor see their own verification', function () {
        fakeBoth();
        startVerification();
        $other = T::session();
        $other['admin_api.tot'] = ['id' => 'ANOTHERTOT000001'] + $other['admin_api.tot'];

        $this->flushSession()->withSession($other + ['cashfree.pending' => CashfreeVerification::first()->verification_id])
            ->postJson('/bank/verify/status')->assertJson(['state' => 'none']);
    });

    it('answers JSON 401 when the session ended', function () {
        $this->postJson('/bank/verify/status')->assertStatus(401);
    });
});

describe('reconciling a payment the vendor never saw', function () {
    it('reports the result to the Admin when the form is opened again', function () {
        fakeBoth(['GET remitter/status' => Http::response(paid())]);
        $row = startVerification();
        $this->flushSession(); // browser closed

        $this->withSession(T::session())->get('/home')->assertOk();

        expect($row->fresh()->reported_status)->toBe('verified');
        expect(T::sent('POST', 'tots/'.T::TOT_ID.'/bank-verification'))->toMatchArray(['status' => 'verified']);
    });

    it('never breaks the page when Cashfree is down', function () {
        fakeBoth(['GET remitter/status' => Http::response([], 500)]);
        startVerification();

        $this->withSession(T::session())->get('/home')->assertOk();
    });

    it('does nothing for old or already reported rows', function () {
        fakeBoth(['GET remitter/status' => Http::response(paid())]);
        $row = startVerification();
        $row->update(['created_at' => now()->subHours(3)]);

        $this->withSession(T::session())->get('/home')->assertOk();
        expect($row->fresh()->reported_status)->toBeNull();
    });
});

describe('Cashfree webhook', function () {
    function webhook(array $data, ?string $secret = 'test-client-secret'): array
    {
        $payload = ['event_type' => 'RPD_BANK_ACCOUNT_VERIFICATION_SUCCESS', 'event_time' => '2026-10-21 10:46:16', 'version' => 'v1', 'data' => $data];
        $payload['signature'] = WebhookSignature::expected($payload, $secret ?? 'x');

        return $payload;
    }

    it('computes the documented signature: sorted data values, HMAC-SHA256, base64', function () {
        $data = ['status' => 'SUCCESS', 'ref_id' => '49', 'verification_id' => '91', 'bank_account' => ''];
        $expected = base64_encode(hash_hmac('sha256', ''.'49'.'SUCCESS'.'91', 's3cret', true)); // bank_account, ref_id, status, verification_id

        expect(WebhookSignature::expected(['data' => $data], 's3cret'))->toBe($expected)
            ->and(WebhookSignature::valid(['data' => $data, 'signature' => $expected], 's3cret'))->toBeTrue()
            ->and(WebhookSignature::valid(['data' => $data, 'signature' => 'wrong'], 's3cret'))->toBeFalse()
            ->and(WebhookSignature::valid(['data' => $data], 's3cret'))->toBeFalse()
            ->and(WebhookSignature::valid(['data' => $data, 'signature' => $expected], null))->toBeFalse();
    });

    it('is reachable without a session or CSRF token and refreshes the row from Cashfree, not from the payload', function () {
        fakeBoth(['GET remitter/status' => Http::response(paid())]);
        $row = startVerification();
        $this->flushSession();

        $this->postJson('/webhooks/cashfree', webhook(['verification_id' => $row->verification_id, 'ref_id' => '3905', 'status' => 'FAILURE']))
            ->assertOk();

        // the (forged) FAILURE in the payload is irrelevant: Cashfree said SUCCESS
        expect($row->fresh()->status)->toBe('SUCCESS')->and($row->fresh()->reported_status)->toBeNull();
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/bank-verification')); // no vendor token here: reported on the next page visit
    });

    it('rejects a wrong or missing signature with a plain 404 and does not call Cashfree', function () {
        fakeBoth();
        $row = startVerification();
        $before = collect(Http::recorded())->count();

        $bad = webhook(['verification_id' => $row->verification_id, 'status' => 'SUCCESS']);
        $bad['signature'] = 'forged';
        $this->postJson('/webhooks/cashfree', $bad)->assertNotFound();
        $this->postJson('/webhooks/cashfree', ['data' => ['verification_id' => $row->verification_id]])->assertNotFound();

        expect(collect(Http::recorded())->count())->toBe($before);
    });

    it('answers 200 for an unknown verification id', function () {
        fakeBoth();

        $this->postJson('/webhooks/cashfree', webhook(['verification_id' => 'nope', 'status' => 'SUCCESS']))->assertOk();
    });

    it('starts no session and sets no cookies', function () {
        fakeBoth();

        $response = $this->postJson('/webhooks/cashfree', webhook(['verification_id' => 'nope']));
        expect($response->headers->getCookies())->toBeEmpty();
    });

    it('logs the inbound call with the signature redacted', function () {
        fakeBoth();
        $this->postJson('/webhooks/cashfree', webhook(['verification_id' => 'nope', 'bank_account' => '026291800001191', 'upi' => 'someone@upi']));

        $log = ApiLog::where('direction', 'in')->sole();
        expect($log->service)->toBe('cashfree')->and($log->request_body)->not->toContain('026291800001191')->not->toContain('someone@upi')
            ->and($log->request_body)->toContain('***********1191');
        expect(json_decode($log->request_body, true)['signature'])->toBe('[REDACTED]');
    });
});

describe('logging', function () {
    it('logs every Cashfree call without credentials, QR codes, UPI ids or full account numbers', function () {
        fakeBoth(['GET remitter/status' => Http::response(paid())]);
        startVerification();
        $this->postJson('/bank/verify/status');

        $rows = ApiLog::where('service', 'cashfree')->get();
        expect($rows)->toHaveCount(2);

        $everything = ApiLog::all()->toJson();
        foreach (['test-client-secret', 'test-client-id', 'iVBORw0KGgoQRDATA', 'someone@upi', '026291800001191', 'upi://pay'] as $secret) {
            expect($everything)->not->toContain($secret);
        }
        expect($rows[0]->request_headers['x-client-secret'])->toBe('[REDACTED]')->and($rows[1]->response_body)->toContain('***********1191');
    });
});

describe('the form page', function () {
    it('has a verify button that posts to its own form, and shows the current status', function () {
        T::fake();
        $html = $this->withSession(T::session())->get('/home')->getContent();

        expect($html)->toContain('Verify bank account')->toContain('data-bank-verify')
            ->toMatch('/<form id="bank-verify" method="POST" action="[^"]+\/bank\/verify">\s*<input type="hidden" name="_token"/')
            ->toContain('Bank account verification:')->toContain('Pending');
    });

    it('shows Verified and offers to verify again', function () {
        T::fake(detail: T::detail(['form' => ['bank' => ['verification_status' => 'verified']]]));
        $html = $this->withSession(T::session())->get('/home')->getContent();

        expect($html)->toContain('<strong>Verified</strong>')->toContain('Verify again');
    });

    it('hides the button when the TOT cannot be edited', function () {
        T::fake(detail: T::detail(['tot' => ['editable' => false]]));

        expect($this->withSession(T::session())->get('/home')->getContent())->not->toContain('data-bank-verify');
    });
});

describe('housekeeping', function () {
    it('purges rows that expired more than two days ago', function () {
        fakeBoth();
        $old = startVerification();
        $old->update(['expires_at' => now()->subDays(3)]);
        $fresh = CashfreeVerification::create(['verification_id' => 'vobfresh', 'tot_ref' => T::TOT_ID, 'status' => 'CREATED', 'expected_fingerprint' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(5)]);

        $this->artisan('cashfree:purge-verifications')->assertSuccessful();

        expect(CashfreeVerification::pluck('verification_id')->all())->toBe([$fresh->verification_id]);
    });

    it('is scheduled daily', function () {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'cashfree:purge-verifications'));

        expect($event)->not->toBeNull()->and($event->expression)->toBe('0 0 * * *');
    });

    it('serves the verification script from the same origin without inline code', function () {
        fakeBoth();
        startVerification();
        $html = $this->get(route('bank.verify'))->getContent();

        expect($html)->toContain('/js/bank-verify.js')->not->toMatch('/\sstyle="|\sonclick=/i')->not->toMatch('/<script(?![^>]*\ssrc=)/i');
        expect(file_exists(public_path('js/bank-verify.js')))->toBeTrue();
    });
});
