<?php

use App\Models\ApiLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TotFixtures as T;

uses(RefreshDatabase::class);

function page(?array $detail = null)
{
    T::fake([], $detail);

    return test()->withSession(T::session())->get('/home');
}

describe('the form page', function () {
    it('renders the reference form from the API data: values, masters, tracker, clauses', function () {
        $html = page()->assertOk()->getContent();

        expect($html)
            ->toContain('Business Development Agreement')
            ->toContain('value="" ') // sanity: inputs render
            ->toContain('Acme Foods Pvt Ltd')                         // vendor name value
            ->toContain('Draft saved 20-Oct-2026, 10:42 AM')
            ->toContain('class="tracker"')->toContain('Vendor Submission')
            ->toContain('Terms of Trade 2026-27')
            ->toContain('Kerala')->toContain('Karnataka')               // /masters states
            ->toContain('Merchandise Vendor')                           // vendor accounting groups
            ->toContain('Gro- Food')->toContain('Toys')                 // categories from masters
            ->toContain('End Cap')->toContain('Pallett')                // deliverable assets from masters
            ->toContain('1. Supply &amp; Invoicing')->toContain('14. Governing Law')  // legal clauses
            ->toContain('L01')->toContain('L02')->toContain('L03')->toContain('V01') // e-sign anchors
            ->toContain('Annexure A')->toContain('Annexure B')
            ->toContain('Status:')->toContain('Pending with vendor');
    });

    it('greys out the locked fields and shows them as (Filled by Lulu)', function () {
        $html = page()->getContent();

        // company code chips (read-only) and the three locked selects/fields are disabled, others are not
        expect($html)->toContain('chip-code">9000')->toContain('chip-code">9001')->toContain('title="9000 - Lulu Regional Office (Kochi)"');

        // Purchase Organization is now a read-only list of codes (form.purchase_orgs), like Company Code
        expect($html)->toContain('id="f-purchase-orgs"')->toContain('chip-code">9000')->toContain('Purchase Organization');

        foreach (['f-vendor-details-vendor-accounting-group', 'f-payment-terms-reconciliation-type'] as $id) {
            expect($html)->toMatch('/<select[^>]*id="'.$id.'"[^>]*disabled/');
        }
        expect($html)->not->toMatch('/id="f-vendor-details-vendor-name"[^>]*disabled/')
            ->and(substr_count($html, '(Filled by Lulu)'))->toBeGreaterThanOrEqual(4);
    });

    it('keeps the buyer-only fields disabled even when the API sends no locked_fields (TOT returned to the vendor)', function () {
        $detail = T::detail();
        $detail['locked_fields'] = [];
        $html = page($detail)->getContent();

        foreach (['f-vendor-details-vendor-accounting-group', 'f-payment-terms-reconciliation-type'] as $id) {
            expect($html)->toMatch('/<select[^>]*id="'.$id.'"[^>]*disabled/');
        }
        foreach (['f-vendor-details-agreement-no', 'f-vendor-details-vendor-code'] as $id) {
            expect($html)->toMatch('/<(?:input|textarea)[^>]*id="'.$id.'"[^>]*disabled/');
        }
        expect($html)->toMatch('/class="chip-box disabled" id="f-company-codes"/')
            ->toMatch('/class="chip-box disabled" id="f-purchase-orgs"/')
            ->not->toMatch('/id="f-vendor-details-vendor-name"[^>]*disabled/');
    });

    it('lists states for Scope Type State, regions for Regional and nothing for PAN India in the scope select', function () {
        $select = function (string $scope) {
            $html = page(T::detail(['form' => ['vendor_details' => ['scope_type' => $scope, 'scope_state' => '']]]))->getContent();
            preg_match('/<select[^>]*id="f-vendor-details-scope-state"[^>]*>(.*?)<\/select>/s', $html, $m);

            return [$m[1] ?? '', $html];
        };

        [$state, $html] = $select('State');
        expect($state)->toContain('>Kerala<')->toContain('>Karnataka<')->not->toContain('>South<');
        expect($html)->toContain('data-scope-state')->toContain('data-scope-type')
            ->toContain('State / Region');

        [$regional] = $select('Regional');
        expect($regional)->toContain('>South<')->not->toContain('>Kerala<');

        [$panIndia] = $select('PAN India');
        expect($panIndia)->not->toContain('>South<')->not->toContain('>Kerala<');
    });

    it('has a SKU Data button on the Margin & Rebate header that opens a popup with the article list', function () {
        config(['sku.articles' => [['article' => '100200', 'description' => 'Basmati Rice 5kg'], ['article' => '100201', 'description' => 'Sunflower Oil 1L']]]);
        $html = page()->getContent();

        expect($html)->toMatch('/<div class="card-header has-action"[^>]*>\s*<span>Margin &amp; Rebate<\/span>\s*<button type="button" class="sku-open" data-sku-open>SKU Data<\/button>/')
            ->toContain('id="sku-dialog"')->toContain('<th>Article</th><th>Description</th><th>Front End Margin</th><th>Back End Margin</th>')
            ->toContain('data-sku-add')->toContain('data-sku-cancel')->toContain('data-sku-confirm')
            ->toContain('100200')->toContain('Basmati Rice 5kg')->toContain('js/sku-data.js');
        // auto-suggest text box, not a dropdown
        expect(file_get_contents(public_path('js/sku-data.js')))->toContain("'combobox'")->toContain('aria-autocomplete')->not->toContain('Select article');
        // only the Margin & Rebate card gets it, and the popup's inputs are never posted (they have no name)
        expect(substr_count($html, 'data-sku-open'))->toBe(1);
        preg_match('/<dialog id="sku-dialog".*?<\/dialog>/s', $html, $m);
        expect($m[0] ?? '')->not->toContain('name="');
    });

    it('suggests exactly the five configured articles with their descriptions', function () {
        $articles = collect(config('sku.articles'))->pluck('description', 'article')->all();

        expect($articles)->toBe([
            '2592106' => 'Oppo M/P A6x 5G 4/128 Ice/Blue',
            '2592108' => 'Oppo M/P A6x 5G 6/128 Ice/Blue',
            '2778859' => 'Oppo M/P Reno 16C 5G 8/256 Wht',
            '2592103' => 'Oppo M/P A6x 5G 4/64 Ice/Blue',
            '2592107' => 'Oppo M/P A6x 5G 6/128 Olive/Green',
        ]);

        $html = page()->getContent();
        expect($html)->toContain('2778859')->toContain('Oppo M/P Reno 16C 5G 8/256 Wht');
    });

    it('offers no SKU Data button when the TOT can no longer be edited', function () {
        $html = page(T::detail(['tot' => ['editable' => false, 'status' => 'pending_buyer', 'status_label' => 'Pending with buyer']]))->getContent();

        expect($html)->not->toContain('data-sku-open')->not->toContain('id="sku-dialog"');
    });

    it('puts the chosen articles into the margin table without a page reload (script contract)', function () {
        $js = file_get_contents(public_path('js/sku-data.js'));

        expect($js)->toContain('showModal')->toContain('form[margins][')->toContain('Select at least one article.')
            ->toContain('data-sku-confirm-yes')->not->toContain('location.reload')
            ->and(config('sku.articles'))->toBeArray();
    });

    it('disables the State / Region select for PAN India only', function () {
        $tag = function (string $scope) {
            $html = page(T::detail(['form' => ['vendor_details' => ['scope_type' => $scope, 'scope_state' => '']]]))->getContent();
            preg_match('/<select[^>]*id="f-vendor-details-scope-state"[^>]*>/', $html, $m);

            return $m[0] ?? '';
        };

        expect($tag('PAN India'))->toContain('disabled')
            ->and($tag('State'))->not->toContain('disabled')
            ->and($tag('Regional'))->not->toContain('disabled');

        $js = file_get_contents(public_path('js/tot-form.js'));
        expect($js)->toContain("scopeType.value === 'PAN India'");
    });

    it('keeps the reviewer comments open on every page load, even if the browser restores a collapsed one', function () {
        $html = page(T::detail(['tot' => ['submitted_at' => '2026-10-21T05:00:00+00:00'], 'reviewer_comments' => [['status' => 'pending_vendor', 'comments' => 'Fix it', 'by' => 'Buyer', 'at' => '2026-10-21']]]))->getContent();

        expect($html)->toMatch('/<details class="card comments-card" open>/')
            ->and(file_get_contents(public_path('js/tot-form.js')))->toContain('details.comments-card')->toContain('pageshow');
    });

    it('carries both lists to the script so the choices follow the Scope Type', function () {
        $html = page()->getContent();

        expect($html)->toMatch('/data-scope-options="[^"]*Kerala[^"]*South/')
            ->and(file_get_contents(public_path('js/tot-form.js')))->toContain('data-scope-type');
    });

    it('puts asterisks only on the fields the API lists as required', function () {
        $html = page()->getContent();

        expect($html)->toMatch('/for="f-vendor-details-vendor-name">Vendor Name\s*<span class="req"/')
            ->and($html)->not->toMatch('/for="f-vendor-details-agreement-no">Vendor Agreement No\s*<span class="req"/')
            ->and($html)->toMatch('/for="f-contacts-sales-phone">Sales Contact No\s*<span class="req"/')
            ->and($html)->toContain('Fill in at least one of the payment day fields');
    });

    it('hides the GST number asterisk for GST Unregistered vendors', function () {
        $html = page(T::detail(['form' => ['vendor_details' => ['gst_type' => 'GST Unregistered']]]))->getContent();

        expect($html)->toMatch('/id="gst-regn-req"\s+hidden/');
    });

    it('shows required documents with a mark, depending on GST / MSME type', function () {
        $html = page()->getContent(); // GST Registered, no MSME type

        expect($html)->toMatch('/PAN Certificate<span class="req">/')
            ->and($html)->toMatch('/GST Certificate<span class="req"\s+id="gst-cert-req"\s*>/')
            ->and($html)->not->toMatch('/MSME Certificate<span class="req">/')
            ->and($html)->toContain('Uploaded: pan.pdf (2 KB)');
    });

    it('pre-selects stored categories, outlets, margins, deliverables and rebate lines', function () {
        $html = page()->getContent();

        expect($html)->toMatch('/id="cat-food"[^>]*>\s*<option value=""><\/option>\s*<option value="Yes" selected/')
            ->and($html)->toContain('name="form[outlets][0][state]"')->toContain('name="form[outlets][1][state]"') // + one blank row
            ->and($html)->toContain('Biscuits')
            ->and($html)->toContain('name="form[deliverables][end_cap][numbers]"')
            ->and($html)->toContain('name="form[rebate_lines][rebate:fixed_rebate][on_invoice]"')
            ->and($html)->toMatch('/name="form\[rebate_lines\]\[rebate:fixed_rebate\]\[on_invoice\]"[^>]*value="2.5"/')
            ->and($html)->toMatch('/name="form\[payment_terms\]\[apply_15_day_rule\]".*?<option value="Yes" selected/s');
    });

    it('does not show a masked account number and tells the browser not to resend it', function () {
        $html = page(T::detail(['form' => ['bank' => ['account_no' => '********9012']]]))->getContent();

        expect($html)->toContain('placeholder="********9012 (leave blank to keep)"')
            ->and($html)->toContain('name="masked[]" value="bank.account_no"')
            ->and($html)->not->toMatch('/name="form\[bank\]\[account_no\]"[^>]*value="\*/');
    });

    it('is read-only once the TOT is no longer editable', function () {
        $html = page(T::detail(['tot' => ['editable' => false, 'status' => 'pending_buyer', 'status_label' => 'Pending with buyer']]))->getContent();

        expect($html)->toContain('can no longer be edited')
            ->and($html)->not->toContain('Save Draft')->not->toContain('doc-up-pan_certificate')
            ->and($html)->toMatch('/id="f-vendor-details-vendor-name"[^>]*disabled/');
    });

    it('shows reviewer comments (no action-needed note on the status bar)', function () {
        $html = page(T::detail(['tot' => ['submitted_at' => '2026-10-21T05:00:00+00:00'], 'reviewer_comments' => [['status' => 'pending_vendor', 'comments' => 'Fix the GST number', 'by' => 'Buyer One', 'at' => '2026-10-21']]]))->getContent();

        expect($html)->toContain('Reviewer comments')->toContain('Fix the GST number')->not->toContain('Action needed');
        // collapsible, and expanded when the page loads
        expect($html)->toMatch('/<details class="card comments-card" open>\s*<summary class="card-header">Reviewer comments/');
    });

    it('caches the masters between page loads', function () {
        T::fake();
        $this->withSession(T::session())->get('/home')->assertOk();
        $this->get('/home')->assertOk();

        $masterCalls = collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/masters'))->count();
        expect($masterCalls)->toBe(1);
    });

    it('never puts the TOT id, a document id or a token in the page URLs or markup', function () {
        $html = page()->getContent();

        expect($html)->not->toContain(T::TOT_ID)->not->toContain(T::TOKEN)->not->toContain('/77');
    });

    it('shows a neutral page when the masters cannot be loaded', function () {
        T::fake(['GET masters' => Http::response([], 500)]);

        $this->withSession(T::session())->get('/home')->assertStatus(503);
    });
});

describe('Save Draft', function () {
    function post_form(array $form, array $extra = [])
    {
        return test()->post('/tot/draft', ['form' => $form] + $extra);
    }

    it('sends the posted sections to PUT /draft and returns to the form', function () {
        T::fake();
        $this->withSession(T::session())->get('/home'); // loads the locked list into the session

        post_form([
            'vendor_details' => ['vendor_name' => 'Acme Foods', 'pan_no' => 'ABCDE1234F', 'brand' => ''],
            'contacts' => ['sales' => ['name' => 'Sam', 'phone' => '+919999999999']],
            'payment_terms' => ['apply_15_day_rule' => 'Yes', 'days_from_grn' => '30'],
            'categories' => ['food' => 'Yes', 'toys' => 'No'],
        ])->assertRedirect(route('home'))->assertSessionHas('status', 'Draft saved.');

        $sent = T::sent('PUT', 'tots/'.T::TOT_ID.'/draft');
        expect($sent['form']['vendor_details'])->toEqual(['vendor_name' => 'Acme Foods', 'pan_no' => 'ABCDE1234F', 'brand' => null])
            ->and($sent['form']['contacts'])->toBe([['type' => 'sales', 'name' => 'Sam', 'phone' => '+919999999999']])
            ->and($sent['form']['payment_terms']['apply_15_day_rule'])->toBeTrue()
            ->and($sent['form']['categories'])->toBe(['food']);
    });

    it('never sends the buyer-only fields even if the browser posts them', function () {
        T::fake();
        $this->withSession(T::session())->get('/home');

        post_form([
            'company_codes' => ['1234'],
            'purchase_orgs' => ['9999'],
            'vendor_details' => ['vendor_name' => 'Acme', 'vendor_accounting_group' => 'SOR'],
            'payment_terms' => ['reconciliation_type' => 'Tenant Payable', 'payment_mode' => 'ePayment'],
        ])->assertRedirect();

        $form = T::sent('PUT', 'tots/'.T::TOT_ID.'/draft')['form'];
        expect($form)->not->toHaveKey('company_codes')->not->toHaveKey('purchase_orgs')
            ->and($form['vendor_details'])->toBe(['vendor_name' => 'Acme'])
            ->and($form['payment_terms'])->toBe(['payment_mode' => 'ePayment']);
    });

    it('does not resend a masked account number the vendor left blank', function () {
        T::fake();
        $this->withSession(T::session())->get('/home');

        post_form(['bank' => ['account_no' => '', 'account_name' => 'Acme']], ['masked' => ['bank.account_no']])->assertRedirect();

        expect(T::sent('PUT', 'tots/'.T::TOT_ID.'/draft')['form']['bank'])->toBe(['account_name' => 'Acme']);
    });

    it('keeps what the vendor typed and shows the Admin format errors beside the fields', function () {
        T::fake(['PUT tots/'.T::TOT_ID.'/draft' => Http::response(['errors' => [
            'vendor_details.pan_no' => ['The PAN is not in the right format.'],
            'contacts.1.phone' => ['The phone number is invalid.'],
        ]], 422)]);
        $this->withSession(T::session())->get('/home');

        $response = $this->from('/home')->post('/tot/draft', ['form' => [
            'vendor_details' => ['pan_no' => 'WRONG', 'vendor_name' => 'Typed Name'],
            'contacts' => ['sales' => ['name' => 'Sam'], 'finance' => ['phone' => '12']],
        ]]);

        $response->assertRedirect('/home')->assertSessionHasErrors(['vendor_details.pan_no', 'contacts.finance.phone']);

        $html = $this->get('/home')->getContent();
        expect($html)->toContain('The PAN is not in the right format.')->toContain('The phone number is invalid.')
            ->and($html)->toContain('Typed Name')->and($html)->toMatch('/name="form\[vendor_details\]\[pan_no\]"[^>]*value="WRONG"/')
            ->and($html)->toContain('Nothing was saved');
    });

    it('tells the vendor when the TOT can no longer be edited (409) or the Admin fails', function (int $status, string $message) {
        T::fake(['PUT tots/'.T::TOT_ID.'/draft' => Http::response(['error' => 'x'], $status)]);
        $this->withSession(T::session())->get('/home');

        $this->from('/home')->post('/tot/draft', ['form' => ['vendor_details' => ['vendor_name' => 'A']]])
            ->assertSessionHasErrors(['form' => $message]);
    })->with([[409, 'This Terms of Trade can no longer be edited.'], [500, 'We could not save right now. Please try again.']]);

    it('does not call the Admin when nothing was posted', function () {
        T::fake();

        $this->withSession(T::session())->post('/tot/draft', [])->assertRedirect();
        expect(T::sent('PUT', 'tots/'.T::TOT_ID.'/draft'))->toBeNull();
    });

    it('is POST only and needs a signed-in vendor', function () {
        $this->get('/tot/draft')->assertStatus(405);
        $this->post('/tot/draft', ['form' => []])->assertRedirect(route('login'));
    });

    it('logs the draft calls without account, PAN or password values', function () {
        T::fake();
        $this->withSession(T::session())->get('/home');
        post_form(['vendor_details' => ['pan_no' => 'ABCDE1234F'], 'bank' => ['account_no' => '123456789012']]);

        $log = ApiLog::where('method', 'PUT')->sole();
        expect($log->request_body)->not->toContain('ABCDE1234F')->not->toContain('123456789012')
            ->and($log->request_body)->toContain('******234F')->toContain('********9012');
    });
});

describe('autosave', function () {
    it('answers JSON with the save time', function () {
        T::fake();
        $this->withSession(T::session())->get('/home');

        $this->postJson('/tot/autosave', ['form' => ['vendor_details' => ['vendor_name' => 'A']]])
            ->assertOk()->assertJson(['saved' => true])->assertJsonStructure(['saved_at']);
    });

    it('answers 422 JSON with the field errors', function () {
        T::fake(['PUT tots/'.T::TOT_ID.'/draft' => Http::response(['errors' => ['vendor_details.pin' => ['Bad PIN']]], 422)]);
        $this->withSession(T::session())->get('/home');

        $this->postJson('/tot/autosave', ['form' => ['vendor_details' => ['pin' => 'x']]])
            ->assertStatus(422)->assertJson(['saved' => false, 'errors' => ['vendor_details.pin' => 'Bad PIN']]);
    });

    it('answers 401 JSON (not a redirect) when the Admin session has ended', function () {
        T::fake(['PUT tots/'.T::TOT_ID.'/draft' => Http::response(['error' => 'unauthenticated'], 401)]);

        $this->withSession(T::session())->postJson('/tot/autosave', ['form' => ['vendor_details' => ['vendor_name' => 'A']]])
            ->assertStatus(401)->assertJson(['error' => 'session_ended']);
    });

    it('answers 401 JSON when the VOB session itself has expired', function () {
        $this->postJson('/tot/autosave', ['form' => []])->assertStatus(401);
    });
});

it('serves the form script and styles from the same origin (no inline script or style attributes)', function () {
    $html = page()->getContent();

    expect($html)->toContain('/js/tot-form.js')->toContain('/css/tot.css')
        ->and($html)->not->toMatch('/<script(?![^>]*\ssrc=)/i')
        ->and($html)->not->toMatch('/\sstyle="/i')
        ->and($html)->not->toMatch('/\sonclick=|\sonchange=/i');
    expect(file_exists(public_path('js/tot-form.js')))->toBeTrue();
});

it('sends form posts to the Admin signed and with the bearer token', function () {
    T::fake();
    $this->withSession(T::session())->get('/home');
    $this->post('/tot/draft', ['form' => ['vendor_details' => ['vendor_name' => 'A']]]);

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->hasHeader('Authorization', 'Bearer '.T::TOKEN) && $r->hasHeader('X-Signature'));
});

describe('bank details, mandatory rules and signatories', function () {
    it('shows Account No twice (the second masked) and the IFS Code limited to 11 characters', function () {
        $html = page()->getContent();

        expect($html)->toMatch('/<input[^>]*type="password"[^>]*id="f-bank-account-no-confirm"/')
            ->toMatch('/<input[^>]*id="f-bank-ifsc"[^>]*maxlength="11"[^>]*data-ifsc/')
            ->toContain('importance-box');
    });

    it('rejects a badly formed IFS Code and capitalises a good one', function () {
        T::fake();
        $this->withSession(T::session())->get('/home');

        $this->from('/home')->post('/tot/draft', ['form' => ['bank' => ['ifsc' => 'HDFC1001234']]])->assertSessionHasErrors('bank.ifsc');
        foreach (['HDFC0001234', 'hdfc0abc123'] as $ok) {
            $this->post('/tot/draft', ['form' => ['bank' => ['ifsc' => $ok]]])->assertSessionDoesntHaveErrors();
        }
        $sent = collect(Http::recorded())->filter(fn ($p) => $p[0]->method() === 'PUT')->map(fn ($p) => $p[0]->data()['form']['bank']['ifsc'])->values()->all();
        expect($sent)->toBe(['HDFC0001234', 'HDFC0ABC123']);
    });

    it('rejects an account number that differs from its confirmation', function () {
        T::fake();
        $this->withSession(T::session())->get('/home');

        $this->from('/home')->post('/tot/draft', ['form' => ['bank' => ['account_no' => '123456789', 'account_no_confirm' => '123456780']]])
            ->assertSessionHasErrors('bank.account_no_confirm');
        $this->post('/tot/draft', ['form' => ['bank' => ['account_no' => '123456789', 'account_no_confirm' => '123456789']]])->assertSessionDoesntHaveErrors();
    });

    it('marks Vendor Type mandatory, and GST Regn. No only for GST Registered', function () {
        $html = page()->getContent();
        expect($html)->toMatch('/for="f-vendor-details-entity-type">Vendor Type\s*<span class="req"\s*>/')
            ->toMatch('/for="f-vendor-details-gst-regn-no">GST Regn\. No\s*<span class="req"\s+id="gst-regn-req"\s*>/');

        foreach (['GST Unregistered', 'GST Compounding'] as $type) {
            $detail = T::detail();
            $detail['form']['vendor_details']['gst_type'] = $type;
            expect(page($detail)->getContent())->toMatch('/id="gst-regn-req"\s*hidden/');
        }
    });

    it('keeps the Lulu signatory fields inactive for the vendor', function () {
        $html = page()->getContent();

        foreach (['name', 'designation', 'contact-no', 'signed-date', 'company-stamp'] as $f) {
            expect($html)->toMatch('/<(?:input|textarea)[^>]*id="f-signatories-bda-lulu-'.$f.'"[^>]*disabled/');
        }
        expect($html)->not->toMatch('/<(?:input|textarea)[^>]*id="f-signatories-bda-vendor-name"[^>]*disabled/');
    });
});

it('has an arrow-only Back to top button floating outside the form', function () {
    $html = page()->getContent();

    expect($html)->toMatch('/<button type="button" class="back-to-top" data-back-to-top[^>]*aria-label="Back to top"[^>]*hidden>/')
        ->and(preg_match('/<form[^>]*id="tot-form".*?<\/form>/s', $html, $m) ? $m[0] : '')->not->toContain('back-to-top');
    expect(file_get_contents(public_path('js/tot-form.js')))->toContain('data-back-to-top')->toContain('scrollTo');
    expect(file_get_contents(public_path('css/tot.css')))->toMatch('/\.back-to-top \{ position:fixed; right:\d+px;/');
});

it('still renders the GST Regn. No asterisk (hidden) when the Admin did not list it, so choosing GST Registered can show it without a reload', function () {
    $detail = T::detail(['form' => ['vendor_details' => ['gst_type' => 'GST Unregistered']]]);
    $detail['required_fields'] = array_values(array_diff($detail['required_fields'], ['vendor_details.gst_regn_no']));

    expect(page($detail)->getContent())->toMatch('/<span class="req"\s+id="gst-regn-req"\s+hidden\s*>/');

    // submit also asks for it only for GST Registered
    $registered = T::detail(['form' => ['vendor_details' => ['gst_type' => 'GST Registered', 'gst_regn_no' => null]]]);
    $registered['required_fields'] = array_values(array_diff($registered['required_fields'], ['vendor_details.gst_regn_no']));
    $missing = app(\App\Services\Tot\SubmitReadiness::class)->missing($registered, true, false);
    expect($missing)->toHaveKey('vendor_details.gst_regn_no');
});
