<?php

use App\Services\Tot\TotFormMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\TotFixtures as T;

uses(RefreshDatabase::class);

/** Behaviour in docs/API_CONTRACT.md sections 5A-5C that VOB depends on. */
describe('required documents (5C: already resolved by the Admin)', function () {
    it('uses document_requirements when present, whatever the vendor GST type is', function () {
        T::fake([], T::detail([
            'document_requirements' => [
                ['type' => 'pan_certificate', 'required' => true, 'uploaded' => true],
                ['type' => 'gst_certificate', 'required' => false, 'uploaded' => false],
                ['type' => 'msme_certificate', 'required' => true, 'uploaded' => false],
                ['type' => 'cancelled_cheque', 'required' => true, 'uploaded' => false],
            ],
        ]));
        $html = test()->withSession(T::session())->get('/home')->getContent(); // vendor is "GST Registered" in the fixture

        expect($html)->toMatch('/PAN Certificate<span class="req">/')->toMatch('/MSME Certificate<span class="req">/')
            ->not->toMatch('/GST Certificate<span class="req">/');
    });

    it('reads plain required_documents type strings (no "(when ...)" text)', function () {
        T::fake([], T::detail(['required_documents' => ['pan_certificate', 'rtgs_form']]));
        $html = test()->withSession(T::session())->get('/home')->getContent();

        expect($html)->toMatch('/RTGS Form<span class="req">/')->not->toMatch('/Cancelled Cheque \/ Bank Letter<span class="req">/');
    });

    it('blocks Submit on the documents the Admin marks required, and no others', function () {
        $detail = readyDetailForContract();
        $detail['document_requirements'] = [['type' => 'pan_certificate', 'required' => true, 'uploaded' => true], ['type' => 'msme_certificate', 'required' => true, 'uploaded' => false]];
        $detail['required_documents'] = ['pan_certificate', 'msme_certificate'];
        T::fake(['POST tots/'.T::TOT_ID.'/submit' => Http::response(['message' => 'ok'])], $detail);

        test()->withSession(T::session())->post('/tot/submit', ['form' => ['vendor_details' => ['vendor_name' => 'A']]])
            ->assertSessionHasErrors(['documents.msme_certificate']);
        expect(session('errors')->getBag('default')->getMessages())->not->toHaveKey('documents.cancelled_cheque');
    });
});

function readyDetailForContract(): array
{
    return T::detail([
        'form' => [
            'vendor_details' => ['gst_regn_no' => '29ABCDE1234F1Z5'],
            'contacts' => [['type' => 'sales', 'phone' => '+919999999999']],
            'bank' => ['verification_status' => 'verified'],
        ],
        'documents' => [['id' => 'DOCIDOPAQUE00001', 'type' => 'pan_certificate', 'name' => 'pan.pdf', 'size' => 10]],
    ]);
}

describe('draft errors (5C: positions in the list VOB sent)', function () {
    it('maps errors on a list row back to the page row even when blank rows were dropped', function () {
        $mapper = new TotFormMapper;
        $payload = $mapper->payload([
            'outlets' => [[], ['state' => 'KL', 'store_1' => 'a'], ['state' => '', 'store_1' => ''], ['state' => 'KA', 'store_3' => 'zzz']],
        ], [], [], []);

        expect($payload['form']['outlets'])->toHaveCount(2);
        $errors = $mapper->errorsByPath(['outlets.0.state' => ['bad'], 'outlets.1.store_3' => ['too long']], $payload['keyed']);

        // sent index 0 = page row 1, sent index 1 = page row 3
        expect($errors)->toBe(['outlets.1.state' => 'bad', 'outlets.3.store_3' => 'too long']);
    });

    it('maps "categories.<index>" to the category code that was sent', function () {
        $mapper = new TotFormMapper;
        $payload = $mapper->payload(['categories' => ['food' => 'Yes', 'fv' => 'No', 'toys' => 'Yes']], [], [], []);

        expect($mapper->errorsByPath(['categories.1' => ['The selected categories.1 is invalid.']], $payload['keyed']))
            ->toBe(['categories.toys' => 'The selected categories.1 is invalid.']);
    });

    it('shows a category error beside its select after a failed save', function () {
        T::fake(['PUT tots/'.T::TOT_ID.'/draft' => Http::response(['message' => 'x', 'errors' => ['categories.0' => ['That category is not active.']]], 422)]);
        $this->withSession(T::session())->get('/home');

        $this->from('/home')->post('/tot/draft', ['form' => ['categories' => ['food' => 'Yes']]])->assertSessionHasErrors(['categories.food']);

        $html = $this->get('/home')->getContent();
        expect($html)->toMatch('/id="cat-food".*?<span class="error" role="alert">That category is not active\.<\/span>/s');
    });

    it('treats a rejected draft as all-or-nothing and tells the vendor nothing was saved', function () {
        T::fake(['PUT tots/'.T::TOT_ID.'/draft' => Http::response(['message' => 'x', 'errors' => ['bank.ifsc' => ['Bad IFSC']]], 422)]);
        $this->withSession(T::session())->get('/home');

        $this->from('/home')->post('/tot/draft', ['form' => ['bank' => ['ifsc' => 'x']]]);
        expect($this->get('/home')->getContent())->toContain('Nothing was saved')->toContain('Bad IFSC');
    });
});

describe('values the Admin returns in clear (5C)', function () {
    it('shows the stored PAN and account number to the signed-in vendor and sends them back unchanged', function () {
        T::fake();
        $html = $this->withSession(T::session())->get('/home')->getContent();

        expect($html)->toMatch('/name="form\[vendor_details\]\[pan_no\]"[^>]*value="ABCDE1234F"/')
            ->toMatch('/name="form\[bank\]\[account_no\]"[^>]*value="123456789012"/')
            ->not->toContain('leave blank to keep');
    });

    it('never sends a value containing * (the Admin rejects it with 422)', function () {
        T::fake();
        $this->withSession(T::session())->get('/home');

        $this->post('/tot/draft', ['form' => ['bank' => ['account_no' => '', 'account_name' => 'Acme']], 'masked' => ['bank.account_no']]);

        expect(T::sent('PUT', 'tots/'.T::TOT_ID.'/draft')['form']['bank'])->toBe(['account_name' => 'Acme']);
    });
});

describe('bank verification record (5B)', function () {
    it('shows the reference and time the Admin recorded', function () {
        T::fake([], T::detail(['form' => ['bank' => ['verification_status' => 'verified', 'verification_reference' => 'DEMO-4F9K2LQ7XA', 'verified_at' => '2026-10-21T05:00:00+00:00']]]));
        $html = $this->withSession(T::session())->get('/home')->getContent();

        expect($html)->toContain('<strong>Verified</strong>')->toContain('reference DEMO-4F9K2LQ7XA')->toContain('21-Oct-2026, 10:30 AM');
    });

    it('shows no reference line while the bank is not verified', function () {
        T::fake();

        expect($this->withSession(T::session())->get('/home')->getContent())->not->toContain('(reference');
    });
});

describe('development flags the Admin reports (5A)', function () {
    it('warns when the Admin and VOB disagree about skipping third-party services', function () {
        T::fake([], T::detail(['validation' => ['required_fields' => true, 'documents' => true, 'skip_third_party' => true]]));
        $html = $this->withSession(T::session())->get('/home')->getContent();

        expect($html)->toContain('the Admin checks skip_third_party is ON, this site has it OFF');
    });

    it('is quiet when both agree', function () {
        config(['vob.skip_third_party' => true]);
        T::fake([], T::detail(['validation' => ['required_fields' => true, 'documents' => true, 'skip_third_party' => true]]));

        expect($this->withSession(T::session())->get('/home')->getContent())->not->toContain('Development note');
    });
});

describe('draft saved time (5A)', function () {
    it('shows the time the Admin stamped when autosaving', function () {
        T::fake(['PUT tots/'.T::TOT_ID.'/draft' => Http::response(['saved' => ['vendor_details'], 'ignored' => [], 'draft_saved_at' => '2026-10-21T06:30:00+00:00'])]);
        $this->withSession(T::session())->get('/home');

        $this->postJson('/tot/autosave', ['form' => ['vendor_details' => ['vendor_name' => 'A']]])
            ->assertOk()->assertJson(['saved' => true, 'saved_at' => '21-Oct-2026, 12:00 PM']);
    });
});

describe('Admin 500s (5C: {"error":"server_error"} with no details)', function () {
    it('shows only a generic message and never echoes the Admin body', function () {
        T::fake(['PUT tots/'.T::TOT_ID.'/draft' => Http::response(['error' => 'server_error', 'message' => 'SQLSTATE[42S22] Unknown column D:\\projects\\x'], 500)]);
        $this->withSession(T::session())->get('/home');

        $this->from('/home')->post('/tot/draft', ['form' => ['vendor_details' => ['vendor_name' => 'A']]])
            ->assertSessionHasErrors(['form' => 'We could not save right now. Please try again.']);
        expect($this->get('/home')->getContent())->not->toContain('SQLSTATE')->not->toContain('server_error');
    });
});

describe('reviewer return (5B/5C)', function () {
    it('shows the comments the Admin lists, newest first, and offers Resubmit', function () {
        T::fake([], T::detail(['tot' => ['submitted_at' => '2026-10-21T05:00:00+00:00'], 'reviewer_comments' => [
            ['status' => 'pending_vendor', 'comments' => 'Second return: fix PAN', 'by' => 'Buyer One', 'at' => '2026-10-23T09:00:00+00:00'],
            ['status' => 'pending_vendor', 'comments' => 'First return: fix GST', 'by' => 'Buyer One', 'at' => '2026-10-22T09:00:00+00:00'],
        ]]));
        $html = $this->withSession(T::session())->get('/home')->getContent();

        expect(strpos($html, 'Second return'))->toBeLessThan(strpos($html, 'First return'))
            ->and($html)->toMatch('/data-submit[^>]*>\s*Resubmit\s*<\/button>/s');
    });
});

describe('resubmission goes back to the stage that rejected it (API_CONTRACT submit + 5B)', function () {
    it('tells the vendor where the TOT will go, from resubmit_to', function (string $status, string $label) {
        T::fake([], T::detail(['tot' => ['submitted_at' => '2026-10-21T05:00:00+00:00'], 'resubmit_to' => $status,
            'reviewer_comments' => [['status' => 'pending_vendor', 'comments' => 'Please fix', 'by' => 'Reviewer', 'at' => '2026-10-22']]]));
        $html = test()->withSession(T::session())->get('/home')->getContent();

        expect($html)->toContain("It will go back to <strong>$label</strong>")->toMatch('/data-submit[^>]*>\s*Resubmit\s*<\/button>/s');
    })->with([
        'buyer' => ['pending_buyer', 'Pending with buyer'],
        'legal' => ['pending_legal', 'Pending with legal'],
        'gm buying' => ['pending_gm_buying', 'Pending with GM Buying'],
    ]);

    it('says nothing about a destination when the Admin does not send one, and never before the first submission', function () {
        T::fake([], T::detail(['resubmit_to' => 'pending_legal', 'tot' => ['submitted_at' => null]]));
        expect(test()->withSession(T::session())->get('/home')->getContent())->not->toContain('It will go back to');

        T::fake([], T::detail(['tot' => ['submitted_at' => '2026-10-21T05:00:00+00:00'], 'reviewer_comments' => [['status' => 'pending_vendor', 'comments' => 'x', 'by' => 'R', 'at' => '2026-10-22']]]));
        expect(test()->get('/home')->getContent())->not->toContain('It will go back to');
    });

    it('accepts any status in the submit answer, not only pending_buyer, and shows the Admin label', function () {
        T::fake(['POST tots/'.T::TOT_ID.'/submit' => Http::response(['message' => 'ok', 'tot' => ['status' => 'pending_legal', 'status_label' => 'Pending with legal'], 'warnings' => []])],
            T::detail(['form' => ['vendor_details' => ['gst_regn_no' => '29ABCDE1234F1Z5']], 'documents' => [], 'document_requirements' => [], 'required_documents' => [], 'tot' => ['submitted_at' => '2026-10-21T05:00:00+00:00']]));
        config(['vob.enforce_required' => false, 'vob.enforce_documents' => false]);

        test()->withSession(T::session())->post('/tot/submit', ['form' => ['vendor_details' => ['vendor_name' => 'A']]])
            ->assertRedirect(route('home'))->assertSessionHas('status', 'Your Terms of Trade has been submitted for review.')->assertSessionHas('submit_warnings', []);

        T::fake([], T::detail(['tot' => ['editable' => false, 'status' => 'pending_legal', 'status_label' => 'Pending with legal', 'submitted_at' => '2026-10-22T05:00:00+00:00']]));
        expect(test()->get('/home')->getContent())->toContain('pending with legal')->not->toContain('pending with buyer');
    });

    it('keeps the ticks the Admin reports for stages that already approved', function () {
        T::fake([], T::detail(['tot' => ['editable' => false, 'status' => 'pending_legal', 'status_label' => 'Pending with legal', 'submitted_at' => '2026-10-22T05:00:00+00:00'],
            'progress' => ['current' => 'legal', 'steps' => [
                ['key' => 'initiated', 'label' => 'TOT initiated', 'state' => 'done', 'entered_at' => null],
                ['key' => 'vendor', 'label' => 'Vendor details & submission', 'state' => 'done', 'entered_at' => null],
                ['key' => 'buyer', 'label' => 'Buyer review', 'state' => 'done', 'entered_at' => null],
                ['key' => 'kam', 'label' => 'KAM review', 'state' => 'done', 'entered_at' => null],
                ['key' => 'legal', 'label' => 'Legal review', 'state' => 'current', 'entered_at' => null],
            ]]]));
        $html = test()->withSession(T::session())->get('/home')->getContent();

        preg_match_all('/class="step (done|current|pending)"/', $html, $m);
        expect($m[1])->toBe(['done', 'done', 'done', 'done', 'current']);
    });
});

it('tells a vendor whose old invitation link was replaced to use the newest email', function () {
    Http::fake(['admin.test/*' => Http::response(['valid' => false, 'error' => 'link_invalid'], 422)]);

    test()->get('/onboard/SOME-OLD-LINK-TOKEN-12345')->assertStatus(410)->assertSee('newer invitation email');
});

describe('purchase organizations (API_CONTRACT: form.purchase_orgs, buyer-only list)', function () {
    it('shows every purchase organization code the buyer assigned, read-only, with its name on hover', function () {
        $html = clausePage(['form' => ['purchase_orgs' => ['9000', '9100']]]);
        $block = $html;

        expect($block)->toMatch('/for="f-purchase-orgs">Purchase Organization\s*<span class="tag-readonly">\((Filled by Lulu)\)/')
            ->toContain('chip-code">9000')->toContain('chip-code">9100')->toContain('title="9000 - Lulu Kerala"')
            ->not->toContain('name="form[purchase_orgs]')->not->toContain('name="form[vendor_details][purchase_org]');
    });

    it('says so while none is assigned yet', function () {
        $detail = T::detail();
        $detail['form']['purchase_orgs'] = []; // an empty list replaces, it does not merge
        T::fake([], $detail);

        expect(test()->withSession(T::session())->get('/home')->getContent())->toContain('Not assigned yet.');
    });

    it('is never sent back (the whole section is locked)', function () {
        T::fake();
        $this->withSession(T::session())->get('/home');

        $this->post('/tot/draft', ['form' => ['purchase_orgs' => ['1111'], 'vendor_details' => ['vendor_name' => 'A']]]);

        expect(T::sent('PUT', 'tots/'.T::TOT_ID.'/draft')['form'])->not->toHaveKey('purchase_orgs');
    });
});

describe('reviewer_comments with stage (API_CONTRACT 5C)', function () {
    it('shows where each rejection happened and where the TOT went next, newest first', function () {
        T::fake([], T::detail(['tot' => ['submitted_at' => '2026-10-21T05:00:00+00:00'], 'reviewer_comments' => [
            ['status' => 'Pending with buyer', 'action' => 'reject', 'stage' => 'Pending with KAM', 'comments' => 'Margins look wrong.', 'by' => 'Kim', 'at' => '2026-10-27T09:30:00+00:00', 'sections' => [], 'section_note' => null],
            ['status' => 'Pending with vendor', 'action' => 'reject', 'stage' => 'Pending with buyer', 'comments' => 'PAN missing.', 'by' => 'Asha', 'at' => '2026-10-26T09:30:00+00:00', 'sections' => [], 'section_note' => null],
        ]]));
        $html = test()->withSession(T::session())->get('/home')->getContent();

        expect($html)->toContain('Margins look wrong.')->toContain('Kim, rejected at Pending with KAM')->toContain('27-Oct-2026, 03:00 PM')
            ->toContain('now: Pending with buyer')->toContain('Asha, rejected at Pending with buyer')->toContain('now: Pending with vendor')
            ->and(strpos($html, 'Margins look wrong.'))->toBeLessThan(strpos($html, 'PAN missing.'));
    });
});
