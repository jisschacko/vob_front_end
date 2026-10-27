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

    it('sends what the vendor typed unchanged, in one request (the Admin skips these checks in demo mode)', function () {
        T::fake();
        $this->withSession(T::session())->get('/home');

        $this->post('/tot/draft', ['form' => [
            'vendor_details' => ['pan_no' => 'not-a-pan', 'gst_regn_no' => '123'], 'bank' => ['account_name' => 'x', 'account_no' => '1', 'ifsc' => 'nope'],
        ]])->assertRedirect(route('home'))->assertSessionHas('status', 'Draft saved.')->assertSessionDoesntHaveErrors();

        $bodies = draftBodies();
        expect($bodies)->toHaveCount(1)
            ->and($bodies[0]['vendor_details'])->toEqual(['pan_no' => 'not-a-pan', 'gst_regn_no' => '123'])
            ->and($bodies[0]['bank'])->toEqual(['account_name' => 'x', 'account_no' => '1', 'ifsc' => 'nope']);
    });

    it('autosaves them the same way', function () {
        T::fake();
        $this->withSession(T::session())->get('/home');

        $this->postJson('/tot/autosave', ['form' => ['bank' => ['ifsc' => 'x']]])->assertOk()->assertJson(['saved' => true]);
    });

    it('shows the Admin error (and no green badge) if the Admin still validates, e.g. its own demo flag is off', function () {
        T::fake(['PUT tots/'.T::TOT_ID.'/draft' => Http::response(['message' => 'x', 'errors' => ['vendor_details.pan_no' => ['The PAN is not in the right format.']]], 422)]);
        $this->withSession(T::session())->get('/home');

        $this->from('/home')->post('/tot/draft', ['form' => ['vendor_details' => ['pan_no' => 'bad']]])->assertSessionHasErrors('vendor_details.pan_no');
        expect(count(draftBodies()))->toBe(1); // no retry, no hidden workaround

        $html = $this->get('/home')->getContent();
        expect($html)->toContain('The PAN is not in the right format.')
            ->and(fieldTag($html, 'vendor-details-pan-no'))->not->toContain('is-valid')
            ->and(fieldTag($html, 'vendor-details-pan-no'))->toContain('value="bad"');
    });

    it('warns a tester when the Admin is not in demo mode, so its format checks still apply', function () {
        T::fake([], T::detail(['validation' => ['required_fields' => true, 'documents' => true, 'skip_third_party' => false]]));

        expect($this->withSession(T::session())->get('/home')->getContent())->toContain('the Admin checks skip_third_party is OFF, this site has it ON');
    });

    it('does not block Submit on the five fields when the Admin has them, and still asks for them when it has not', function () {
        $detail = T::detail(['form' => ['vendor_details' => ['pan_no' => 'whatever', 'gst_regn_no' => '123'], 'bank' => ['account_name' => 'n', 'account_no' => '1', 'ifsc' => 'z', 'verification_status' => 'verified'],
            'contacts' => [['type' => 'sales', 'phone' => '+911234567']]]]);
        $detail['required_fields'] = ['vendor_details.pan_no', 'vendor_details.gst_regn_no', 'bank.account_name', 'bank.account_no', 'bank.ifsc', 'contacts.sales.phone'];
        $detail['document_requirements'] = [];
        $detail['required_documents'] = [];
        T::fake(['POST tots/'.T::TOT_ID.'/submit' => Http::response(['message' => 'ok', 'tot' => ['status' => 'pending_buyer'], 'warnings' => []])], $detail);

        $this->withSession(T::session())->post('/tot/submit', ['form' => ['vendor_details' => ['pan_no' => 'whatever']]])->assertRedirect(route('home'))->assertSessionDoesntHaveErrors();

        $detail['form']['bank']['ifsc'] = null;
        T::fake(['POST tots/'.T::TOT_ID.'/submit' => Http::response(['message' => 'ok', 'tot' => ['status' => 'pending_buyer'], 'warnings' => []])], $detail);
        $this->post('/tot/submit', ['form' => ['vendor_details' => ['pan_no' => 'whatever']]])->assertSessionHasErrors(['bank.ifsc']);
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
