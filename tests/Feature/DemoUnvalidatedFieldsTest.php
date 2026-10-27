<?php

use App\Support\DemoLenientFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TotFixtures as T;

uses(RefreshDatabase::class);

const DEMO_FIELDS = ['vendor_details.pan_no', 'vendor_details.gst_regn_no', 'bank.account_name', 'bank.account_no', 'bank.ifsc'];

/** The attributes of the input / textarea with this id, as one string (parsed as HTML, so line breaks and ordering do not matter). */
function fieldTag(string $html, string $id): string
{
    $dom = new DOMDocument;
    @$dom->loadHTML($html, LIBXML_NOERROR);
    $element = (new DOMXPath($dom))->query('//*[@id="f-'.$id.'"]')->item(0);
    if (! $element) {
        return '';
    }

    $attributes = [];
    foreach ($element->attributes as $attribute) {
        $attributes[] = $attribute->name.'="'.$attribute->value.'"';
    }

    return $element->nodeName.' '.implode(' ', $attributes);
}

/** All draft PUT bodies, in order. */
function draftBodies(): array
{
    return collect(Http::recorded())->map(fn ($p) => $p[0])
        ->filter(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/draft'))
        ->map(fn (Request $r) => $r->data()['form'])->values()->all();
}

function demoPage(array $over = [])
{
    T::fake([], T::detail($over));

    return test()->withSession(T::session())->get('/home')->getContent();
}

/** The Admin refuses the draft over these fields (bad PAN, IFSC, ...). */
function refuseFields(array $paths, array $extraRoutes = []): void
{
    $errors = [];
    foreach ($paths as $path) {
        $errors[$path] = ['The '.$path.' format is invalid.'];
    }
    T::fake(['PUT tots/'.T::TOT_ID.'/draft' => function (Request $r) use ($errors, $paths) {
        // refuse only while one of the fields is in the body
        foreach ($paths as $path) {
            [$section, $field] = explode('.', $path, 2);
            if (array_key_exists($field, $r->data()['form'][$section] ?? [])) {
                return Http::response(['message' => 'x', 'errors' => $errors], 422);
            }
        }

        return Http::response(['saved' => ['vendor_details'], 'ignored' => [], 'draft_saved_at' => '2026-10-27T05:00:00+00:00']);
    }] + $extraRoutes);
}

describe('demo mode OFF: nothing changes', function () {
    it('shows no green badge and keeps the Admin format errors', function () {
        $html = demoPage();
        expect($html)->not->toContain('valid-badge')->not->toContain('data-demo-valid');

        refuseFields(['vendor_details.pan_no']);
        $this->withSession(T::session())->get('/home');
        $this->from('/home')->post('/tot/draft', ['form' => ['vendor_details' => ['pan_no' => 'bad']]])->assertSessionHasErrors('vendor_details.pan_no');
        expect(count(draftBodies()))->toBe(1); // no retry
    });
});

describe('demo mode ON (VOB_SKIP_THIRD_PARTY)', function () {
    beforeEach(fn () => config(['vob.skip_third_party' => true]));

    it('lists exactly the five fields from the request', function () {
        expect(DemoLenientFields::paths())->toBe(DEMO_FIELDS);
    });

    it('shows a green "Verified" on PAN, GST number, account name, account number and IFSC when they have a value', function () {
        $html = demoPage(['form' => ['vendor_details' => ['gst_regn_no' => '29ABCDE1234F1Z5'], 'bank' => ['ifsc' => 'HDFC0001234']]]);

        foreach (['vendor-details-pan-no', 'vendor-details-gst-regn-no', 'bank-account-name', 'bank-account-no', 'bank-ifsc'] as $id) {
            expect(fieldTag($html, $id))->toContain('is-valid')->toContain('data-demo-valid');
        }
        expect(substr_count($html, '<p class="valid-badge" data-demo-valid-badge  >&#10003; Verified</p>'))->toBe(0); // badge markup below is checked loosely
        preg_match_all('/<p class="valid-badge" data-demo-valid-badge([^>]*)>&#10003; Verified<\/p>/', $html, $badges);
        expect($badges[0])->toHaveCount(5);
        foreach ($badges[1] as $attrs) {
            expect($attrs)->not->toContain('hidden'); // all five have a value
        }
    });

    it('hides the badge on an empty field (it appears as soon as something is typed)', function () {
        $html = demoPage(['form' => ['vendor_details' => ['pan_no' => null]]]);

        expect($html)->toMatch('/data-demo-valid-badge\s+hidden\s*>&#10003; Verified/');
        expect($html)->not->toMatch('/<(input|textarea) class="f[^"]*is-valid[^"]*"[^>]*id="f-vendor-details-pan-no"/');
    });

    it('does not decorate any other field', function () {
        $html = demoPage();

        expect(substr_count($html, 'data-demo-valid-badge'))->toBe(5)
            ->and($html)->not->toMatch('/id="f-vendor-details-vendor-name"[^>]*data-demo-valid/')
            ->and($html)->not->toMatch('/id="f-contacts-sales-phone"[^>]*data-demo-valid/');
    });

    it('has the script that shows the badge when a field loses focus, and no inline code', function () {
        $js = file_get_contents(public_path('js/tot-form.js'));
        expect($js)->toContain('[data-demo-valid]')->toContain('is-valid')->toContain("addEventListener('blur'");
        // shown on lost focus, not while typing: the input handler only clears the marker
        expect($js)->toMatch('/addEventListener\(.input., function \(\) \{ show\(false\); \}\)/');

        expect(demoPage())->not->toMatch('/\sstyle="/i')->not->toMatch('/<script(?![^>]*\ssrc=)/i');
    });

    it('saves everything else and tells the vendor "Draft saved" when the Admin refuses only these fields', function () {
        refuseFields(['vendor_details.pan_no', 'bank.ifsc']);
        $this->withSession(T::session())->get('/home');

        $this->post('/tot/draft', ['form' => [
            'vendor_details' => ['vendor_name' => 'Acme', 'pan_no' => 'not-a-pan'],
            'bank' => ['account_name' => 'Acme', 'account_no' => '1', 'ifsc' => 'nope'],
            'contacts' => ['sales' => ['name' => 'Sam']],
        ]])->assertRedirect(route('home'))->assertSessionHas('status', 'Draft saved.')->assertSessionDoesntHaveErrors();

        [$first, $second] = draftBodies();
        expect($first['vendor_details'])->toHaveKey('pan_no')                       // first try sends everything
            ->and($second['vendor_details'])->toBe(['vendor_name' => 'Acme'])       // retry: without the refused field
            ->and($second['bank'])->toBe(['account_name' => 'Acme', 'account_no' => '1']);
        expect($second['contacts'])->toHaveCount(1);
    });

    it('also retries when the Admin refuses a field we do not list, as long as only listed fields are refused', function () {
        refuseFields(['bank.account_no']);
        $this->withSession(T::session())->get('/home');

        $this->post('/tot/draft', ['form' => ['bank' => ['account_no' => 'ABC']]])->assertRedirect(route('home'))->assertSessionHas('status', 'Draft saved.');
        expect(count(draftBodies()))->toBe(1); // the retry would be empty, so nothing more is sent
    });

    it('still shows other format errors, and hides the ones about the five fields', function () {
        T::fake(['PUT tots/'.T::TOT_ID.'/draft' => Http::response(['message' => 'x', 'errors' => [
            'vendor_details.pan_no' => ['Bad PAN'], 'vendor_details.pin' => ['Bad PIN'], 'bank.ifsc' => ['Bad IFSC'],
        ]], 422)]);
        $this->withSession(T::session())->get('/home');

        $this->from('/home')->post('/tot/draft', ['form' => ['vendor_details' => ['pan_no' => 'x', 'pin' => 'x']]])
            ->assertSessionHasErrors(['vendor_details.pin']);

        $errors = session('errors')->getBag('default')->getMessages();
        expect($errors)->toHaveKey('vendor_details.pin')->not->toHaveKey('vendor_details.pan_no')->not->toHaveKey('bank.ifsc');
        expect(count(draftBodies()))->toBe(1); // a real error: no retry
    });

    it('shows what the vendor typed again after the page reloads, even though the Admin never kept it', function () {
        refuseFields(['vendor_details.pan_no']);
        $this->withSession(T::session())->get('/home');
        $this->post('/tot/draft', ['form' => ['vendor_details' => ['pan_no' => 'whatever-i-typed']]]);

        T::fake([], T::detail(['form' => ['vendor_details' => ['pan_no' => null]]])); // Admin has no PAN
        $html = $this->get('/home')->getContent();

        expect($html)->toMatch('/name="form\[vendor_details\]\[pan_no\]"[^>]*value="whatever-i-typed"/')
            ->and(fieldTag($html, 'vendor-details-pan-no'))->toContain('is-valid');
    });

    it('forgets the typed value when the vendor clears the field', function () {
        refuseFields(['vendor_details.pan_no']);
        $this->withSession(T::session())->get('/home');
        $this->post('/tot/draft', ['form' => ['vendor_details' => ['pan_no' => 'abc']]]);
        $this->post('/tot/draft', ['form' => ['vendor_details' => ['pan_no' => '']]]);

        T::fake([], T::detail(['form' => ['vendor_details' => ['pan_no' => null]]]));
        expect($this->get('/home')->getContent())->not->toMatch('/name="form\[vendor_details\]\[pan_no\]"[^>]*value="abc"/');
    });

    it('autosave behaves the same way (no error for the five fields)', function () {
        refuseFields(['bank.ifsc']);
        $this->withSession(T::session())->get('/home');

        $this->postJson('/tot/autosave', ['form' => ['bank' => ['ifsc' => 'x'], 'vendor_details' => ['vendor_name' => 'Acme']]])
            ->assertOk()->assertJson(['saved' => true]);
    });

    it('does not block Submit on these fields even when the Admin has none of them (required checks on)', function () {
        $detail = T::detail(['form' => [
            'vendor_details' => ['pan_no' => null, 'gst_regn_no' => null], 'bank' => ['account_name' => null, 'account_no' => null, 'ifsc' => null, 'verification_status' => 'verified'],
            'contacts' => [['type' => 'sales', 'phone' => '+911234567']],
        ]]);
        // lists are replaced, not merged
        $detail['required_fields'] = ['vendor_details.pan_no', 'vendor_details.gst_regn_no', 'bank.account_name', 'bank.account_no', 'bank.ifsc', 'contacts.sales.phone'];
        $detail['document_requirements'] = [];
        $detail['required_documents'] = [];

        // the Admin refuses the five fields on every draft save and has none of them stored
        refuseFields(DEMO_FIELDS, ['GET tots/'.T::TOT_ID => Http::response($detail), 'GET masters' => Http::response(T::masters()),
            'POST tots/'.T::TOT_ID.'/submit' => Http::response(['message' => 'ok', 'tot' => ['status' => 'pending_buyer'], 'warnings' => []])]);

        // the typed values are remembered, so the local required check passes
        $this->withSession(T::session())->post('/tot/submit', ['form' => ['vendor_details' => ['pan_no' => 'x', 'gst_regn_no' => 'y'], 'bank' => ['account_name' => 'n', 'account_no' => '1', 'ifsc' => 'z']]])
            ->assertRedirect(route('home'))->assertSessionDoesntHaveErrors();

        // but a field the vendor never typed is still reported as required (it is only skipped once typed)
        $this->flushSession()->withSession(T::session())->post('/tot/submit', ['form' => ['vendor_details' => ['pan_no' => 'x']]])
            ->assertSessionHasErrors(['bank.account_name']);
    });

    it('lets the bank be "verified" without any bank details at the Admin', function () {
        T::fake(['POST tots/'.T::TOT_ID.'/bank-verification' => Http::response(['saved' => true])], T::detail(['form' => ['bank' => ['account_name' => null, 'account_no' => null, 'ifsc' => null]]]));

        $this->withSession(T::session())->post('/bank/verify')->assertRedirect(route('home'))->assertSessionDoesntHaveErrors();
        expect(T::sent('POST', 'tots/'.T::TOT_ID.'/bank-verification')['status'])->toBe('verified');
    });

    it('is never active in production', function () {
        app()->detectEnvironment(fn () => 'production');

        expect(DemoLenientFields::active())->toBeFalse()->and(DemoLenientFields::is('bank.ifsc'))->toBeFalse();
        expect(demoPage())->not->toContain('data-demo-valid');
    });
});
