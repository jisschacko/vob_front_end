<?php

use App\Models\ApiLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TotFixtures as T;

uses(RefreshDatabase::class);

const GSTIN_OK = '29AAICP2912R1ZR';

/**
 * Fake the Admin (by "METHOD path" under /api/v1) and Cashfree (by "METHOD path" under /verification) together.
 * Answers may be callables; a ConnectionException instance is thrown.
 */
function cfFake(array $cashfree = [], array $admin = [], ?array $detail = null): void
{
    Http::swap(new Factory);

    $adminRoutes = $admin + [
        'POST tots/'.T::TOT_ID.'/bank-verification' => Http::response(['saved' => true]),
        'GET tots/'.T::TOT_ID => Http::response($detail ?? T::detail()),
        'PUT tots/'.T::TOT_ID.'/draft' => Http::response(['saved' => ['vendor_details'], 'ignored' => []]),
        'GET masters' => Http::response(T::masters()),
    ];

    Http::fake(function (Request $request) use ($adminRoutes, $cashfree) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (str_contains($request->url(), 'cashfree.com')) {
            $key = $request->method().' '.ltrim(substr($path, strlen('/verification')), '/');
            $answer = $cashfree[$key] ?? Http::response(['type' => 'x', 'code' => 'unknown', 'message' => 'not faked: '.$key], 404);
        } else {
            $key = $request->method().' '.ltrim(substr($path, strlen('/api/v1')), '/');
            $answer = $adminRoutes[$key] ?? Http::response(['error' => 'not faked: '.$key], 404);
        }
        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return is_callable($answer) ? $answer($request) : $answer;
    });
}

function cfCalls(string $suffix): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => str_contains($r->url(), 'cashfree.com') && str_ends_with(parse_url($r->url(), PHP_URL_PATH), $suffix))->values()->all();
}

function verifyPost(string $what, array $data = [])
{
    return test()->withSession(T::session())->postJson('/verify/'.$what, $data);
}

beforeEach(function () {
    config(['cashfree.client_id' => 'test-id', 'cashfree.client_secret' => 'test-secret', 'cashfree.env' => 'sandbox']);
});

describe('PAN', function () {
    it('asks Cashfree POST /pan with the PAN and shows the registered name when valid', function () {
        cfFake(['POST pan' => Http::response(['valid' => true, 'pan_status' => 'VALID', 'type' => 'Individual', 'registered_name' => 'JOHN DOE'])]);

        verifyPost('pan', ['pan' => ' abcpv1234d '])->assertOk()
            ->assertJson(['state' => 'valid', 'detail' => ['name' => 'JOHN DOE', 'type' => 'Individual']]);

        $calls = cfCalls('/pan');
        expect($calls)->toHaveCount(1)
            ->and($calls[0]->url())->toBe('https://sandbox.cashfree.com/verification/pan')
            ->and($calls[0]->data())->toBe(['pan' => 'ABCPV1234D'])
            ->and($calls[0]->header('x-client-id'))->toBe(['test-id']);
    });

    it('reports a PAN that is not valid or not active as invalid', function (array $answer) {
        cfFake(['POST pan' => Http::response($answer)]);

        verifyPost('pan', ['pan' => 'ABCDE1234F'])->assertOk()->assertJson(['state' => 'invalid']);
    })->with([
        'not valid' => [['valid' => false, 'message' => 'Invalid PAN']],
        'deactivated' => [['valid' => true, 'pan_status' => 'DEACTIVATED']],
        'deleted' => [['valid' => true, 'pan_status' => 'DELETED']],
    ]);

    it('refuses a wrong shape without calling Cashfree', function () {
        cfFake();

        verifyPost('pan', ['pan' => 'NOT-A-PAN'])->assertOk()->assertJson(['state' => 'invalid']);

        expect(cfCalls('/pan'))->toBe([]);
    });

    it('says only "could not verify" (never Cashfree\'s words) when Cashfree cannot answer, and asks again next time', function (mixed $answer) {
        cfFake(['POST pan' => $answer]);

        $r = verifyPost('pan', ['pan' => 'ABCDE1234F'])->assertOk()->assertJson(['state' => 'unavailable']);
        expect($r->json('message'))->not->toContain('whitelist')->not->toContain('balance')->not->toContain('Cashfree');

        verifyPost('pan', ['pan' => 'ABCDE1234F']);
        // a refused connection never reaches Laravel's request log, every other answer does
        expect(cfCalls('/pan'))->toHaveCount($answer instanceof Throwable ? 0 : 2); // not remembered
    })->with([
        'ip not whitelisted' => fn () => Http::response(['type' => 'authentication_error', 'code' => 'ip_validation_failed', 'message' => 'IP not whitelisted your current ip is 1.2.3.4'], 403),
        'no balance' => fn () => Http::response(['type' => 'validation_error', 'code' => 'insufficient_balance', 'message' => 'low balance'], 422),
        'bad credentials' => fn () => Http::response(['type' => 'authentication_error', 'code' => 'authentication_failed'], 401),
        'cashfree down' => fn () => Http::response(['code' => 'verification_failed'], 500),
        'rate limited' => fn () => Http::response(['code' => 'too_many_requests_per_ip'], 429),
        'no connection' => fn () => new ConnectionException('timeout'),
    ]);

    it('pays for a value only once per sign-in', function () {
        cfFake(['POST pan' => Http::response(['valid' => true, 'pan_status' => 'VALID'])]);

        $test = test()->withSession(T::session());
        $test->postJson('/verify/pan', ['pan' => 'ABCDE1234F'])->assertJson(['state' => 'valid']);
        $test->postJson('/verify/pan', ['pan' => 'abcde1234f'])->assertJson(['state' => 'valid']);

        expect(cfCalls('/pan'))->toHaveCount(1);
    });
});

describe('GSTIN', function () {
    it('asks Cashfree POST /gstin with the upper-case GSTIN key and shows the legal name', function () {
        cfFake(['POST gstin' => Http::response(['valid' => true, 'message' => 'GSTIN Exists', 'gst_in_status' => 'Active', 'legal_name_of_business' => 'ACME FOODS PRIVATE LIMITED'])]);

        verifyPost('gstin', ['gstin' => strtolower(GSTIN_OK)])->assertOk()
            ->assertJson(['state' => 'valid', 'detail' => ['name' => 'ACME FOODS PRIVATE LIMITED', 'status' => 'Active']]);

        $calls = cfCalls('/gstin');
        expect($calls)->toHaveCount(1)->and($calls[0]->data())->toBe(['GSTIN' => GSTIN_OK]);
    });

    it('reports an unknown or not-active GSTIN as invalid', function (array $answer) {
        cfFake(['POST gstin' => Http::response($answer)]);

        verifyPost('gstin', ['gstin' => GSTIN_OK])->assertOk()->assertJson(['state' => 'invalid']);
    })->with([
        'not found' => [['valid' => false, 'message' => "GSTIN Doesn't Exist"]],
        'cancelled' => [['valid' => true, 'gst_in_status' => 'Cancelled']],
        'suspended' => [['valid' => true, 'gst_in_status' => 'Suspended']],
    ]);

    it('refuses a wrong shape without calling Cashfree', function () {
        cfFake();

        verifyPost('gstin', ['gstin' => '123'])->assertJson(['state' => 'invalid']);

        expect(cfCalls('/gstin'))->toBe([]);
    });
});

describe('IFSC', function () {
    it('asks Cashfree POST /ifsc and returns bank, branch, address, MICR and the live payment modes', function () {
        cfFake(['POST ifsc' => Http::response([
            'status' => 'VALID', 'bank' => 'HDFC BANK', 'branch' => 'MUMBAI MAIN', 'address' => 'Fort, Mumbai', 'city' => 'MUMBAI', 'state' => 'MAHARASHTRA',
            'micr' => 400240002, 'neft' => 'Live', 'rtgs' => 'Live', 'imps' => 'Live', 'upi' => 'Not Live',
        ])]);

        verifyPost('ifsc', ['ifsc' => 'hdfc0000001'])->assertOk()->assertJson(['state' => 'valid', 'detail' => [
            'bank' => 'HDFC BANK', 'branch' => 'MUMBAI MAIN', 'address' => 'Fort, Mumbai', 'micr' => '400240002', 'modes' => ['NEFT', 'RTGS', 'IMPS'],
        ]]);

        $calls = cfCalls('/ifsc');
        expect($calls)->toHaveCount(1)->and($calls[0]->data()['ifsc'])->toBe('HDFC0000001')->and($calls[0]->data()['verification_id'])->toStartWith('vobifsc');
    });

    it('reports an IFSC Cashfree does not know as invalid', function () {
        cfFake(['POST ifsc' => Http::response(['type' => 'not_found_error', 'code' => 'ifsc_not_found', 'message' => 'IFSC not found'], 404)]);

        verifyPost('ifsc', ['ifsc' => 'HDFC0999999'])->assertOk()->assertJson(['state' => 'invalid']);
    });

    it('refuses a wrong shape without calling Cashfree', function () {
        cfFake();

        verifyPost('ifsc', ['ifsc' => 'HDFC123'])->assertJson(['state' => 'invalid']);

        expect(cfCalls('/ifsc'))->toBe([]);
    });
});

describe('bank account (Cashfree penny drop, nothing to scan)', function () {
    $valid = fn () => Http::response([
        'reference_id' => 5001, 'account_status' => 'VALID', 'account_status_code' => 'ACCOUNT_IS_VALID',
        'name_at_bank' => 'ACME FOODS PVT LTD', 'name_match_result' => 'GOOD_PARTIAL_MATCH', 'bank_name' => 'HDFC BANK',
    ]);

    it('checks the SAVED account name, number and IFSC, tells the Admin it is verified, and returns the name at the bank', function () use ($valid) {
        cfFake(['POST bank-account/sync' => $valid()]);

        verifyPost('bank')->assertOk()->assertJson(['state' => 'valid', 'detail' => ['name_at_bank' => 'ACME FOODS PVT LTD', 'name_match' => 'GOOD_PARTIAL_MATCH']]);

        $calls = cfCalls('/bank-account/sync');
        expect($calls)->toHaveCount(1)
            ->and($calls[0]->data())->toBe(['bank_account' => '123456789012', 'ifsc' => 'HDFC0001234', 'name' => 'Acme'])
            ->and(T::sent('POST', 'tots/'.T::TOT_ID.'/bank-verification'))->toBe(['status' => 'verified', 'reference' => 'CF-5001']);
    });

    it('tells the Admin it failed when the bank cannot confirm the account', function () {
        cfFake(['POST bank-account/sync' => Http::response(['account_status' => 'INVALID', 'account_status_code' => 'INVALID_ACCOUNT_FAIL'])]);

        verifyPost('bank')->assertOk()->assertJson(['state' => 'invalid']);

        expect(T::sent('POST', 'tots/'.T::TOT_ID.'/bank-verification'))->toBe(['status' => 'failed']);
    });

    it('records nothing when Cashfree cannot answer', function () {
        cfFake(['POST bank-account/sync' => Http::response(['code' => 'verification_failed'], 500)]);

        verifyPost('bank')->assertOk()->assertJson(['state' => 'unavailable']);

        expect(T::sent('POST', 'tots/'.T::TOT_ID.'/bank-verification'))->toBeNull();
    });

    it('does not mark the account verified when the Admin could not record it, and does not cache that', function () use ($valid) {
        cfFake(['POST bank-account/sync' => $valid()], ['POST tots/'.T::TOT_ID.'/bank-verification' => Http::response(['error' => 'x'], 500)]);

        verifyPost('bank')->assertStatus(502)->assertJson(['state' => 'unavailable']);
    });

    it('does not pay twice for the same details once they are verified at the Admin', function () use ($valid) {
        $detail = T::detail(['form' => ['bank' => ['verification_status' => 'verified']]]);
        cfFake(['POST bank-account/sync' => $valid()], [], $detail);

        $test = test()->withSession(T::session());
        $test->postJson('/verify/bank')->assertJson(['state' => 'valid']);
        $test->postJson('/verify/bank')->assertJson(['state' => 'valid']);

        expect(cfCalls('/bank-account/sync'))->toHaveCount(1);
    });

    it('re-records the known answer, without a new Cashfree call, when the Admin reset it to pending after a save', function () use ($valid) {
        cfFake(['POST bank-account/sync' => $valid()]); // the Admin keeps saying "pending"

        $test = test()->withSession(T::session());
        $test->postJson('/verify/bank')->assertJson(['state' => 'valid']);
        $test->postJson('/verify/bank')->assertJson(['state' => 'valid']);

        expect(cfCalls('/bank-account/sync'))->toHaveCount(1);
        $recorded = collect(Http::recorded())->filter(fn ($p) => str_ends_with(parse_url($p[0]->url(), PHP_URL_PATH), '/bank-verification'))->count();
        expect($recorded)->toBe(2);
    });

    it('refuses a tampered bank request (any payload or query) and calls nobody', function () use ($valid) {
        cfFake(['POST bank-account/sync' => $valid()]);

        $test = test()->withSession(T::session());
        $test->postJson('/verify/bank', ['account_no' => '1234567890'])->assertStatus(400);
        $test->postJson('/verify/bank', ['ifsc' => 'HDFC0000001'])->assertStatus(400);
        $test->postJson('/verify/bank?account_no=1')->assertStatus(400);

        expect(cfCalls('/bank-account/sync'))->toHaveCount(0);
        expect(T::sent('POST', 'tots/'.T::TOT_ID.'/bank-verification'))->toBeNull();
    });

    it('rejects a name that does not match only when configured to', function () {
        $noMatch = ['reference_id' => 5002, 'account_status' => 'VALID', 'name_at_bank' => 'SOMEONE ELSE', 'name_match_result' => 'NO_MATCH'];

        config(['cashfree.name_match' => 'ignore']);
        cfFake(['POST bank-account/sync' => Http::response($noMatch)]);
        verifyPost('bank')->assertJson(['state' => 'valid']);

        // another account name = another value, so the first answer is not reused
        config(['cashfree.name_match' => 'reject_no_match']);
        cfFake(['POST bank-account/sync' => Http::response($noMatch)], [], T::detail(['form' => ['bank' => ['account_name' => 'Other Name']]]));
        verifyPost('bank')->assertJson(['state' => 'invalid']);
    });

    it('asks to re-enter a masked account number and to fill all three details first', function (array $bank, string $text) {
        cfFake([], [], T::detail(['form' => ['bank' => $bank]]));

        verifyPost('bank')->assertOk()->assertJson(['state' => 'incomplete'])->assertSee($text);

        expect(cfCalls('/bank-account/sync'))->toBe([]);
    })->with([
        'masked' => [['account_no' => '********9012'], 're-enter the full account number'],
        'no name' => [['account_name' => ''], 'Enter the account name'],
        'no ifsc' => [['ifsc' => ''], 'Enter the account name'],
    ]);

    it('does nothing for a Terms of Trade that can no longer be edited', function () {
        cfFake([], [], T::detail(['tot' => ['editable' => false]]));

        verifyPost('bank')->assertStatus(409);

        expect(cfCalls('/bank-account/sync'))->toBe([]);
    });
});

describe('who may use it', function () {
    it('needs a signed-in vendor', function (string $what) {
        cfFake();

        $this->postJson('/verify/'.$what, [])->assertStatus(401);

        expect(collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'cashfree.com'))->count())->toBe(0);
    })->with(['pan', 'gstin', 'ifsc', 'bank']);

    it('is POST only', function (string $what) {
        cfFake();

        foreach (['get', 'put', 'patch', 'delete'] as $verb) {
            $this->withSession(T::session())->$verb('/verify/'.$what)->assertStatus(405);
        }
    })->with(['pan', 'gstin', 'ifsc', 'bank']);

    it('is throttled, because every call can cost money', function () {
        cfFake(['POST pan' => Http::response(['valid' => true, 'pan_status' => 'VALID'])]);

        $test = test()->withSession(T::session());
        $codes = collect(range(1, 32))->map(fn ($i) => $test->postJson('/verify/pan', ['pan' => 'ABCDE1234F'])->status());

        expect($codes->first())->toBe(200)->and($codes->last())->toBe(429);
    });
});

describe('demo mode (VOB_SKIP_THIRD_PARTY)', function () {
    it('calls nothing and reports everything valid', function () {
        config(['vob.skip_third_party' => true]);
        cfFake();

        verifyPost('pan', ['pan' => 'ABCDE1234F'])->assertJson(['state' => 'valid']);
        verifyPost('gstin', ['gstin' => GSTIN_OK])->assertJson(['state' => 'valid']);
        verifyPost('ifsc', ['ifsc' => 'HDFC0001234'])->assertJson(['state' => 'valid']);
        verifyPost('bank')->assertJson(['state' => 'valid']);

        expect(collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'cashfree.com'))->count())->toBe(0);
    });
});

describe('logging', function () {
    it('logs the calls with the PAN, GSTIN and account number masked and no credentials', function () {
        cfFake([
            'POST pan' => Http::response(['valid' => true, 'pan_status' => 'VALID', 'pan' => 'ABCDE1234F']),
            'POST gstin' => Http::response(['valid' => true, 'GSTIN' => GSTIN_OK, 'gst_in_status' => 'Active']),
            'POST bank-account/sync' => Http::response(['reference_id' => 1, 'account_status' => 'VALID']),
        ]);

        verifyPost('pan', ['pan' => 'ABCDE1234F']);
        verifyPost('gstin', ['gstin' => GSTIN_OK]);
        verifyPost('bank');

        $dump = ApiLog::query()->where('service', 'cashfree')->get()->map(fn ($l) => json_encode($l->getAttributes()))->implode('|');
        expect(ApiLog::query()->where('service', 'cashfree')->count())->toBe(3)
            ->and($dump)->not->toContain('ABCDE1234F')->not->toContain(GSTIN_OK)->not->toContain('123456789012')->not->toContain('test-secret')
            ->and($dump)->toContain('234F')->toContain('9012');
    });
});

describe('the form page', function () {
    it('offers "For failed validations" only after the bank could not confirm the account', function () {
        T::fake();
        expect($this->withSession(T::session())->get('/home')->getContent())->toMatch('/data-override-box\s+hidden/');

        T::fake(['GET tots/'.T::TOT_ID => Http::response(T::detail(['form' => ['bank' => ['verification_status' => 'failed']]]))]);
        expect($this->withSession(T::session())->get('/home')->getContent())->not->toMatch('/data-override-box\s+hidden/');
    });

    it('marks PAN, GST, IFSC and the bank fields for the background checks, with the script', function () {
        T::fake();
        $html = $this->withSession(T::session())->get('/home')->getContent();

        expect($html)->toContain('js/field-verify.js')
            ->toMatch('/id="f-vendor-details-pan-no"[^>]*data-verify="pan"[^>]*data-verify-url="[^"]+\/verify\/pan"/')
            ->toMatch('/id="f-vendor-details-gst-regn-no"[^>]*data-verify="gstin"[^>]*data-verify-url="[^"]+\/verify\/gstin"/')
            ->toMatch('/id="f-bank-ifsc"[^>]*data-verify="ifsc"[^>]*data-verify-url="[^"]+\/verify\/ifsc"/')
            ->toMatch('/id="f-bank-account-name"[^>]*data-verify="bank"/')
            ->toMatch('/id="f-bank-account-no"[^>]*data-verify="bank"/')
            ->toMatch('/id="f-bank-account-no-confirm"[^>]*data-verify="bank"[^>]*data-verify-url="[^"]+\/verify\/bank"/')
            ->toContain('data-verify-msg')->toContain('data-verify-detail')->toContain('data-ifsc-fill');
    });

    it('leaves those fields alone in demo mode and when the TOT is read-only', function () {
        config(['vob.skip_third_party' => true]);
        T::fake();
        expect($this->withSession(T::session())->get('/home')->getContent())->not->toContain('data-verify=');

        config(['vob.skip_third_party' => false]);
        T::fake([], T::detail(['tot' => ['editable' => false, 'status' => 'pending_buyer']]));
        expect($this->withSession(T::session())->get('/home')->getContent())->not->toContain('data-verify=');
    });

    it('has a script that saves the draft first and posts the checks', function () {
        $js = file_get_contents(public_path('js/field-verify.js'));

        expect($js)->toContain('vobAutosave')->toContain('X-CSRF-TOKEN')->toContain("method: 'POST'")->not->toContain('location.reload');
        expect(file_get_contents(public_path('js/tot-form.js')))->toContain('window.vobAutosave');
    });
});

describe('Submit', function () {
    $session = fn (string $state) => T::session() + ['verify.cache.pan:'.hash('sha256', 'ABCDE1234F') => ['state' => $state, 'message' => 'x', 'detail' => []]];

    it('is blocked while the PAN is known to be invalid', function () use ($session) {
        cfFake();

        $this->withSession($session('invalid'))->from('/home')->post('/tot/submit', [])->assertSessionHasErrors('vendor_details.pan_no');

        expect(T::sent('POST', 'tots/'.T::TOT_ID.'/submit'))->toBeNull();
    });

    it('is not blocked by a PAN that is valid or could not be checked', function (string $state) use ($session) {
        cfFake();

        $r = $this->withSession($session($state))->from('/home')->post('/tot/submit', []);

        expect(session('errors')?->has('vendor_details.pan_no') ?? false)->toBeFalse();
    })->with(['valid', 'unavailable']);

    it('is not blocked by an invalid PAN in demo mode', function () use ($session) {
        config(['vob.skip_third_party' => true]);
        cfFake();

        $this->withSession($session('invalid'))->from('/home')->post('/tot/submit', []);

        expect(session('errors')?->has('vendor_details.pan_no') ?? false)->toBeFalse();
    });
});

describe('settings', function () {
    it('defaults the name match to reject_no_match and has no bank-method switch any more', function () {
        $config = file_get_contents(config_path('cashfree.php'));

        expect($config)->toContain("env('CASHFREE_NAME_MATCH', 'reject_no_match')")->not->toContain('BANK_METHOD')
            ->and(file_get_contents(base_path('.env.example')))->toContain("\nCASHFREE_NAME_MATCH=reject_no_match\n")->not->toContain('CASHFREE_BANK_METHOD');
    });

    it('checks a PAN with the PAN alone: no name and no date of birth are sent', function () {
        cfFake(['POST pan' => Http::response(['valid' => true, 'pan_status' => 'VALID'])]);

        verifyPost('pan', ['pan' => 'ABCDE1234F', 'name' => 'John', 'dob' => '1990-01-01']);

        expect(cfCalls('/pan')[0]->data())->toBe(['pan' => 'ABCDE1234F']);
    });
});

describe('the saved, verified bank values', function () {
    it('show a green tick under Account No and IFS Code only while the Admin says verified', function () {
        T::fake(['GET tots/'.T::TOT_ID => Http::response(T::detail(['form' => ['bank' => ['verification_status' => 'verified', 'account_no' => '**********1191', 'ifsc' => 'YESB0000262']]]))]);
        $html = $this->withSession(T::session())->get('/home')->getContent();
        expect(preg_match_all('/data-bank-ok\s*>/', $html))->toBe(2);

        T::fake();
        expect(preg_match_all('/data-bank-ok\s+hidden\s*>/', $this->withSession(T::session())->get('/home')->getContent()))->toBe(2);
    });
});
