<?php

use App\Models\ApiLog;
use App\Models\CashfreeVerification;
use App\Support\EnforcementSwitches;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TotFixtures as T;

uses(RefreshDatabase::class);

/** A TOT that is complete enough to pass every check: all required fields, documents and a verified bank. */
function readyDetail(array $over = []): array
{
    $documents = array_map(fn ($t) => ['id' => crc32($t), 'type' => $t, 'name' => "$t.pdf", 'size' => 1000], ['pan_certificate', 'cancelled_cheque', 'rtgs_form', 'gst_certificate']);

    return T::detail(array_replace_recursive([
        'form' => [
            'vendor_details' => ['gst_regn_no' => '29ABCDE1234F1Z5'],
            'contacts' => [['type' => 'sales', 'name' => 'Sam', 'phone' => '+919999999999', 'email' => 'sam@acme.test']],
            'bank' => ['verification_status' => 'verified'],
        ],
        'documents' => $documents,
    ], $over));
}

function submitRoutes(array $extra = []): array
{
    return $extra + ['POST tots/'.T::TOT_ID.'/submit' => Http::response(['message' => 'Submitted', 'tot' => ['status' => 'pending_buyer']])];
}

function goSubmit(array $form = ['vendor_details' => ['vendor_name' => 'Acme Foods Pvt Ltd']])
{
    return test()->withSession(T::session())->post('/tot/submit', ['form' => $form]);
}

function submitted(): bool
{
    return collect(Http::recorded())->contains(fn ($p) => $p[0]->method() === 'POST' && str_ends_with($p[0]->url(), '/submit'));
}

describe('submitting', function () {
    it('saves the form, then submits it to the Admin and shows the confirmation', function () {
        T::fake(submitRoutes(), readyDetail());

        goSubmit()->assertRedirect(route('home'))
            ->assertSessionHas('status', 'Your Terms of Trade has been submitted for review.');

        expect(T::sent('PUT', 'tots/'.T::TOT_ID.'/draft')['form']['vendor_details']['vendor_name'])->toBe('Acme Foods Pvt Ltd');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/tots/'.T::TOT_ID.'/submit') && $r->hasHeader('Authorization', 'Bearer '.T::TOKEN));

        // order: draft saved before the submit call
        $calls = collect(Http::recorded())->map(fn ($p) => $p[0]->method().' '.basename(parse_url($p[0]->url(), PHP_URL_PATH)))->values();
        expect($calls->search('PUT draft'))->toBeLessThan($calls->search('POST submit'));
    });

    it('stops with the Admin errors when the draft itself has format errors, without submitting', function () {
        T::fake(submitRoutes(['PUT tots/'.T::TOT_ID.'/draft' => Http::response(['errors' => ['vendor_details.pan_no' => ['Bad PAN']]], 422)]), readyDetail());

        goSubmit(['vendor_details' => ['pan_no' => 'x']])->assertSessionHasErrors(['vendor_details.pan_no' => 'Bad PAN']);
        expect(submitted())->toBeFalse();
    });

    it('lists what is missing (from the Admin required lists) and does not submit', function () {
        T::fake(submitRoutes(), T::detail(['form' => ['contacts' => [['phone' => null]]]])); // no GST number, sales phone is empty, no payment terms, docs missing, bank pending

        $response = goSubmit(['payment_terms' => []])->assertSessionHasErrors(['submit']);
        $errors = session('errors')->getBag('default')->getMessages();

        expect($errors)->toHaveKeys(['vendor_details.gst_regn_no', 'contacts.sales.phone', 'documents.cancelled_cheque', 'documents.rtgs_form', 'bank.verification'])
            ->and($errors['vendor_details.gst_regn_no'][0])->toBe('GST Regn. No is required.')
            ->and($errors['contacts.sales.phone'][0])->toBe('Sales Contact No is required.')
            ->and($errors['documents.rtgs_form'][0])->toBe('RTGS Form must be uploaded.')
            ->and($errors)->not->toHaveKey('documents.pan_certificate')   // already uploaded
            ->and($errors)->not->toHaveKey('vendor_details.vendor_name'); // filled
        expect(submitted())->toBeFalse();
    });

    it('treats "at least one payment term" as the API defines it, and skips GST for GST Unregistered', function () {
        $detail = readyDetail(['form' => ['vendor_details' => ['gst_type' => 'GST Unregistered', 'gst_regn_no' => null], 'payment_terms' => ['days_from_grn' => null, 'z_days' => 15]]]);
        T::fake(submitRoutes(), $detail);

        goSubmit()->assertRedirect(route('home'));

        $none = readyDetail(['form' => ['payment_terms' => ['days_from_grn' => null]]]);
        T::fake(submitRoutes(), $none);
        goSubmit()->assertSessionHasErrors('payment_terms.days_from_statement');
    });

    it('accepts the manual-approval override instead of a verified bank', function () {
        T::fake(submitRoutes(), readyDetail(['form' => ['bank' => ['verification_status' => 'failed', 'validation_failed_override' => true]]]));

        goSubmit()->assertRedirect(route('home'));
        expect(submitted())->toBeTrue();
    });

    it('needs the GST / MSME certificate only when registered', function () {
        // GST Registered (fixture default): GST certificate missing -> blocked
        $detail = readyDetail();
        $detail['documents'] = array_values(array_filter($detail['documents'], fn ($d) => $d['type'] !== 'gst_certificate'));
        T::fake(submitRoutes(), $detail);

        goSubmit()->assertSessionHasErrors(['documents.gst_certificate']);

        $detail['form']['vendor_details']['gst_type'] = 'GST Unregistered';
        T::fake(submitRoutes(), $detail);
        goSubmit()->assertRedirect(route('home'));
    });

    it('relays the Admin\'s own "incomplete" list', function () {
        T::fake(submitRoutes(['POST tots/'.T::TOT_ID.'/submit' => Http::response(['error' => 'incomplete', 'errors' => ['bank.verification' => 'Bank must be verified.', 'vendor_details.city' => 'City is required.']], 422)]), readyDetail());

        goSubmit()->assertSessionHasErrors(['submit', 'bank.verification', 'vendor_details.city']);
    });

    it('explains a 409 and an Admin failure', function (int $status, string $message) {
        T::fake(submitRoutes(['POST tots/'.T::TOT_ID.'/submit' => Http::response(['error' => 'x'], $status)]), readyDetail());

        goSubmit()->assertSessionHasErrors(['form' => $message]);
    })->with([[409, 'This Terms of Trade can no longer be edited.'], [500, 'We could not submit right now. Please try again.']]);

    it('is POST only, needs a signed-in vendor and is rate limited', function () {
        $this->get('/tot/submit')->assertStatus(405);
        $this->post('/tot/submit')->assertRedirect(route('sign-in'));

        T::fake(submitRoutes(), readyDetail());
        foreach (range(1, 9) as $i) {
            $this->withSession(T::session())->post('/tot/submit', ['form' => ['vendor_details' => ['vendor_name' => 'A']]])->assertStatus(302);
        }
        $this->withSession(T::session())->post('/tot/submit', ['form' => []])->assertStatus(429);
    });

    it('answers a 401 from the Admin by ending the session', function () {
        T::fake(submitRoutes(['POST tots/'.T::TOT_ID.'/submit' => Http::response(['error' => 'unauthenticated'], 401)]), readyDetail());

        goSubmit()->assertRedirect(route('sign-in'));
    });
});

describe('development switches', function () {
    it('skips the required-field check when VOB_ENFORCE_REQUIRED is off, but still calls the Admin', function () {
        config(['vob.enforce_required' => false]);
        T::fake(submitRoutes(), readyDetail(['form' => ['vendor_details' => ['vendor_name' => null], 'contacts' => [['type' => 'sales', 'phone' => null]]]]));

        goSubmit(['vendor_details' => ['brand' => 'x']])->assertRedirect(route('home'));
        expect(submitted())->toBeTrue();
    });

    it('skips the document and bank checks when VOB_ENFORCE_DOCUMENTS is off', function () {
        config(['vob.enforce_documents' => false]);
        T::fake(submitRoutes(), T::detail(['form' => ['vendor_details' => ['gst_regn_no' => '29ABCDE1234F1Z5'], 'contacts' => [['type' => 'sales', 'phone' => '+911234567']]]]));

        goSubmit()->assertRedirect(route('home'));
        expect(submitted())->toBeTrue();
    });

    it('shows what the Admin let through when it submits with missing items (warnings)', function () {
        config(['vob.enforce_required' => false]);
        T::fake(submitRoutes(['POST tots/'.T::TOT_ID.'/submit' => Http::response(['message' => 'ok', 'warnings' => ['vendor_details.city' => 'City is required.']])]), readyDetail());

        goSubmit()->assertSessionHas('submit_warnings', ['City is required.']);
    });

    it('formats stay validated by the Admin whatever the switches say', function () {
        config(['vob.enforce_required' => false, 'vob.enforce_documents' => false]);
        T::fake(submitRoutes(['PUT tots/'.T::TOT_ID.'/draft' => Http::response(['errors' => ['vendor_details.pin' => ['Bad PIN']]], 422)]), readyDetail());

        goSubmit(['vendor_details' => ['pin' => 'abc']])->assertSessionHasErrors('vendor_details.pin');
        expect(submitted())->toBeFalse();
    });

    it('is always strict in production, whatever the env says', function () {
        config(['vob.enforce_required' => false, 'vob.enforce_documents' => false]);
        app()->detectEnvironment(fn () => 'production');
        $this->withoutMiddleware(ValidateCsrfToken::class); // CSRF is only skipped in the test environment
        T::fake(submitRoutes(), T::detail());

        goSubmit()->assertSessionHasErrors(['submit']);
        expect(submitted())->toBeFalse();
    });

    it('tells a tester when the Admin and VOB switches disagree (never in production)', function () {
        T::fake(submitRoutes(), readyDetail(['validation' => ['required_fields' => false, 'documents' => true]]));
        $html = $this->withSession(T::session())->get('/home')->getContent();
        expect($html)->toContain('the Admin checks required_fields is OFF, this site has it ON');

        app()->detectEnvironment(fn () => 'production');
        expect($this->withSession(T::session())->get('/home')->getContent())->not->toContain('Development note');
    });
});

describe('status and resubmit', function () {
    it('shows the Submit button, with a confirmation prompt, on a first submission', function () {
        T::fake([], readyDetail());
        $html = $this->withSession(T::session())->get('/home')->getContent();

        expect($html)->toMatch('/<button type="submit" class="btn btn-secondary" formaction="[^"]+\/tot\/submit" data-submit/')
            ->toContain('data-confirm="Submit this Terms of Trade for review?')
            ->toMatch('/data-submit[^>]*>\s*Submit\s*<\/button>/s')
            ->not->toContain('Resubmit');
        expect(file_get_contents(public_path('js/tot-form.js')))->toContain('data-submit')->toContain('window.confirm');
    });

    it('shows a read-only status page after submission: tracker kept, no buttons, form disabled', function () {
        T::fake([], readyDetail(['tot' => ['editable' => false, 'status' => 'pending_buyer', 'status_label' => 'Pending with buyer', 'submitted_at' => '2026-10-21T05:00:00+00:00'],
            'progress' => ['current' => 'buyer', 'steps' => [
                ['key' => 'initiated', 'label' => 'TOT initiated', 'state' => 'done', 'entered_at' => null],
                ['key' => 'vendor', 'label' => 'Vendor details & submission', 'state' => 'done', 'entered_at' => null],
                ['key' => 'buyer', 'label' => 'Buyer review', 'state' => 'current', 'entered_at' => null],
            ]]]));
        $html = $this->withSession(T::session())->get('/home')->getContent();

        expect($html)->toContain('Submitted')->toContain('21-Oct-2026, 10:30 AM')->toContain('pending with buyer')
            ->toContain('class="tracker"')->toMatch('/<li class="step current"[^>]*>\s*<span class="dot" data-n="3">/')
            ->not->toContain('Save Draft')->not->toContain('data-submit')->not->toContain('data-bank-verify')
            ->toMatch('/id="f-vendor-details-vendor-name"[^>]*disabled/');
    });

    it('turns Submit into Resubmit when a reviewer sent it back, and shows the comments', function () {
        T::fake([], readyDetail([
            'tot' => ['submitted_at' => '2026-10-21T05:00:00+00:00'],
            'reviewer_comments' => [['status' => 'pending_vendor', 'comments' => 'GST number is wrong', 'by' => 'Buyer One', 'at' => '2026-10-22']],
            'progress' => ['current' => 'vendor', 'steps' => [['key' => 'vendor', 'label' => 'Vendor details & submission', 'state' => 'current', 'entered_at' => null]]],
        ]));
        $html = $this->withSession(T::session())->get('/home')->getContent();

        expect($html)->toMatch('/data-submit[^>]*>\s*Resubmit\s*<\/button>/s')->toContain('data-confirm="Resubmit this Terms of Trade for review?')
            ->toContain('A reviewer sent this Terms of Trade back to you')->toContain('GST number is wrong')
            ->toContain('Action needed: see reviewer comments')->toContain('Save Draft');
    });

    it('lets a returned TOT be resubmitted through the same endpoint', function () {
        T::fake(submitRoutes(), readyDetail(['tot' => ['submitted_at' => '2026-10-21T05:00:00+00:00'], 'reviewer_comments' => [['comments' => 'Fix']]]));

        goSubmit(['vendor_details' => ['gst_regn_no' => '29ABCDE1234F1Z5']])->assertRedirect(route('home'));
        expect(submitted())->toBeTrue();
    });

    it('shows the confirmation on the status page after a submit', function () {
        T::fake(submitRoutes(), readyDetail());
        $this->withSession(T::session())->post('/tot/submit', ['form' => ['vendor_details' => ['vendor_name' => 'A']]]);

        T::fake([], readyDetail(['tot' => ['editable' => false, 'status' => 'pending_buyer', 'status_label' => 'Pending with buyer']]));
        $this->get('/home')->assertSee('Your Terms of Trade has been submitted for review.');
    });
});

describe('demo mode (VOB_SKIP_THIRD_PARTY)', function () {
    beforeEach(function () {
        config(['vob.skip_third_party' => true]);
        Storage::fake('local');
        Storage::fake('s3');
    });

    it('shows a banner on every page', function () {
        T::fake([], readyDetail());

        expect($this->withSession(T::session())->get('/home')->getContent())->toContain('DEMO: third-party services are skipped');
        $this->get('/')->assertSee('DEMO: third-party services are skipped');
    });

    it('marks the bank account verified at the Admin without calling Cashfree', function () {
        T::fake(['POST tots/'.T::TOT_ID.'/bank-verification' => Http::response(['saved' => true])], readyDetail(['form' => ['bank' => ['account_no' => '**********1191']]]));

        $this->withSession(T::session())->post('/bank/verify')->assertRedirect(route('home'))
            ->assertSessionHas('status', 'Demo mode: bank verification was skipped and the account is marked as verified.');

        $sent = T::sent('POST', 'tots/'.T::TOT_ID.'/bank-verification');
        expect($sent['status'])->toBe('verified')->and($sent['reference'])->toStartWith('DEMO-');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'cashfree'));
        expect(CashfreeVerification::count())->toBe(0)->and(ApiLog::where('service', 'cashfree')->count())->toBe(0);
    });

    it('still needs the bank fields to be filled in and reports an Admin failure', function () {
        T::fake([], readyDetail(['form' => ['bank' => ['account_no' => null]]]));
        $this->withSession(T::session())->from('/home')->post('/bank/verify')->assertSessionHasErrors('bank_verify');

        T::fake(['POST tots/'.T::TOT_ID.'/bank-verification' => Http::response([], 500)], readyDetail());
        $this->from('/home')->post('/bank/verify')->assertSessionHasErrors(['bank_verify' => 'We could not record the verification right now. Please try again.']);
    });

    it('stores documents on the local disk instead of S3 and still registers them with the Admin', function () {
        T::fake([], readyDetail());

        $this->withSession(T::session())->post('/tot/documents', ['type' => 'pan_certificate', 'file' => UploadedFile::fake()->createWithContent('pan.pdf', "%PDF-1.4\nx")])
            ->assertRedirect(route('home'));

        expect(Storage::disk('local')->allFiles())->toHaveCount(1)->and(Storage::disk('s3')->allFiles())->toBeEmpty();
        expect(T::sent('POST', 'tots/'.T::TOT_ID.'/documents')['path'])->toBe(Storage::disk('local')->allFiles()[0]);
    });

    it('lets a vendor complete the whole journey with no third party: bank, documents, submit', function () {
        T::fake(submitRoutes(['POST tots/'.T::TOT_ID.'/bank-verification' => Http::response(['saved' => true])]), readyDetail());

        $this->withSession(T::session())->post('/bank/verify')->assertRedirect(route('home'));
        $this->post('/tot/submit', ['form' => ['vendor_details' => ['vendor_name' => 'A']]])->assertRedirect(route('home'));

        Http::assertNotSent(fn (Request $r) => ! str_contains($r->url(), 'admin.test'));
    });

    it('is ignored in production and when the flag is off', function () {
        config(['vob.skip_third_party' => false]);
        T::fake([], readyDetail());
        expect($this->withSession(T::session())->get('/home')->getContent())->not->toContain('DEMO:');

        config(['vob.skip_third_party' => true]);
        app()->detectEnvironment(fn () => 'production');
        expect(EnforcementSwitches::skipThirdParty())->toBeFalse();
        expect($this->get('/home')->getContent())->not->toContain('DEMO:');
    });
});

describe('first visit (regression: the Admin lists system history in reviewer_comments)', function () {
    it('shows Submit, no "sent back" note and no reviewer comments before the first submission', function () {
        $history = [['status' => 'TOT Initiated', 'comments' => 'TOT initiated', 'by' => 'VOB Admin', 'at' => '2026-10-20T11:46:32+00:00']];
        T::fake([], readyDetail(['reviewer_comments' => $history, 'tot' => ['submitted_at' => null]]));

        $html = $this->withSession(T::session())->get('/home')->getContent();

        expect($html)->toMatch('/data-submit[^>]*>\s*Submit\s*<\/button>/s')
            ->not->toContain('Resubmit')->not->toContain('A reviewer sent this Terms of Trade back')
            ->not->toContain('Reviewer comments')->not->toContain('Action needed: see reviewer comments')->not->toContain('VOB Admin');
    });

    it('ignores the initiation row and empty comments even after a return', function () {
        T::fake([], readyDetail(['tot' => ['submitted_at' => '2026-10-21T05:00:00+00:00'], 'reviewer_comments' => [
            ['status' => 'TOT Initiated', 'comments' => 'TOT initiated', 'by' => 'VOB Admin', 'at' => '2026-10-20'],
            ['status' => 'Pending with vendor', 'comments' => null, 'by' => 'system', 'at' => '2026-10-20'],
            ['status' => 'Pending with vendor', 'comments' => 'Please correct the PAN', 'by' => 'Buyer One', 'at' => '2026-10-22'],
        ]]));

        $html = $this->withSession(T::session())->get('/home')->getContent();

        expect($html)->toContain('Please correct the PAN')->not->toContain('VOB Admin')->toContain('Resubmit');
    });
});
