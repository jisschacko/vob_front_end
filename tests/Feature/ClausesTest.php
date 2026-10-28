<?php

use App\Services\Tot\ClauseText;
use App\Services\Tot\TotFormMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\TotFixtures as T;

uses(RefreshDatabase::class);

function clausePage(array $over = []): string
{
    T::fake([], T::detail($over));

    return test()->withSession(T::session())->get('/home')->getContent();
}

/** The HTML of one clause card. */
function clauseCard(string $html, string $key): string
{
    preg_match('/<div class="card clause-card" id="clause_'.$key.'".*?(?=<div class="card clause-card"|<div class="card">\s*<div class="card-header">Signatories)/s', $html, $m);

    return $m[0] ?? '';
}

describe('the 14 Terms of Trade sections (API_CONTRACT: clause_templates)', function () {
    it('renders every clause from the API templates, in order, with a pencil icon on the right of the title', function () {
        $html = clausePage();

        foreach (T::clauseTemplates() as $tpl) {
            $card = clauseCard($html, $tpl['key']);
            expect($card)->not->toBe('')
                ->toContain(e($tpl['number'].'. '.$tpl['title']))
                ->toContain('data-clause-toggle')->toContain('aria-label="Edit '.e($tpl['title']).'"');
        }

        preg_match_all('/id="clause_([a-z_]+)"/', $html, $m);
        expect($m[1])->toBe(array_column(T::clauseTemplates(), 'key'));
        // pencil sits inside the title bar, after the title text
        expect($html)->toMatch('/<div class="card-header clause-header">\s*<span>1\. Supply &amp; Invoicing.*?<\/span>\s*<button type="button" class="pencil"/s');
    });

    it('does not hard-code the wording: whatever the Admin sends as the original is what is shown', function () {
        $templates = T::clauseTemplates();
        $templates[6]['text'] = "a) Brand new original wording from the Admin.\n\nb) Second paragraph.";
        $html = clausePage(['clause_templates' => $templates]);

        expect(clauseCard($html, 'termination'))->toContain('Brand new original wording from the Admin.')->toContain('Second paragraph.');
    });

    it('shows paragraphs separated by a blank line and keeps single line breaks inside a paragraph', function () {
        $card = clauseCard(clausePage(), 'data_privacy');

        expect(substr_count($card, '<p>'))->toBe(3)                       // a), b), and the list paragraph
            ->and($card)->toContain('(i) As required by law.<br />')->toContain('(ii) Order processing.');
    });

    it('shows the original in a textarea only after the pencil is used: closed, disabled and not posted by default', function () {
        $card = clauseCard(clausePage(), 'termination');

        expect($card)->toMatch('/<div class="clause-edit" id="clause-edit-termination"\s+hidden\s*>/')
            ->toMatch('/<textarea[^>]*name="form\[clauses\]\[termination\]"[^>]*data-original="a\) Original wording of clause 7[^"]*"[^>]*disabled\s*>/s')
            ->toContain('Restore original wording')->toContain('aria-expanded="false"');
        // the textarea is filled with the original text
        expect($card)->toMatch('/<textarea[^>]*>a\) Original wording of clause 7, first sentence\. Second sentence\.\s*b\) Original paragraph b of clause 7\.<\/textarea>/s');
    });

    it('renders the placeholders of clause 5 and 6 as the number inputs of business_terms, in the text and beside the textarea', function () {
        $html = clausePage(['form' => ['business_terms' => ['refund_within_days' => 7, 'min_shelf_life_percent' => 60]]]);

        $five = clauseCard($html, 'damaged_defective');
        expect($five)->toContain('Payments shall be refunded within')
            ->and(substr_count($five, 'name="form[business_terms][refund_within_days]"'))->toBe(2) // in the sentence + beside the textarea
            ->and($five)->toMatch('/data-sync="refund_within_days"/')->toContain('value="7"')
            ->and($five)->not->toContain('{refund_within_days} days from the date of intimation</p>'); // the marker is replaced in the view

        $six = clauseCard($html, 'other_obligations');
        expect($six)->toContain('name="form[business_terms][min_shelf_life_percent]"')->toContain('max="100"')->toContain('value="60"');

        // every id stays unique even though the field exists twice
        preg_match_all('/\sid="([^"]+)"/', $html, $ids);
        expect($ids[1])->toBe(array_values(array_unique($ids[1])));
    });

    it('keeps the markers in the textarea text', function () {
        $card = clauseCard(clausePage(), 'damaged_defective');

        expect($card)->toMatch('/<textarea[^>]*>a\) Payments shall be refunded within \{refund_within_days\} days/s');
    });

    it('shows no number inputs in clauses without placeholders', function () {
        expect(clauseCard(clausePage(), 'audit'))->not->toContain('business_terms')->not->toContain('clause-fields');
    });
});

describe('a clause the vendor edited', function () {
    it('shows the edited wording, opens the textarea enabled with it, and marks the clause as edited', function () {
        $html = clausePage(['form' => ['clauses' => ['termination' => "a) Either party may terminate with 60 days notice.\n\nb) Edited second paragraph."]]]);
        $card = clauseCard($html, 'termination');

        expect($card)->toContain('Edited by you')->toContain('Either party may terminate with 60 days notice.')
            ->toMatch('/<textarea[^>]*name="form\[clauses\]\[termination\]"(?![^>]*disabled)[^>]*>a\) Either party may terminate with 60 days notice\./s')
            ->toContain('data-original="a) Original wording of clause 7')   // the original stays available for "Restore"
            ->not->toContain('Original paragraph b of clause 7.</p>');
        // other clauses are untouched
        expect(clauseCard($html, 'audit'))->not->toContain('Edited by you');
    });

    it('escapes whatever the vendor typed', function () {
        $html = clausePage(['form' => ['clauses' => ['audit' => 'a) <script>alert(1)</script> "quoted" & done']]]);
        $card = clauseCard($html, 'audit');

        expect($card)->not->toContain('<script>alert')->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
    });

    it('is read-only (no pencil, no textarea) once the TOT cannot be edited, but still shows the edited wording', function () {
        $html = clausePage(['tot' => ['editable' => false, 'status' => 'pending_buyer', 'status_label' => 'Pending with buyer'],
            'form' => ['clauses' => ['termination' => 'a) Edited wording stays visible.']]]);
        $card = clauseCard($html, 'termination');

        expect($card)->toContain('Edited wording stays visible.')->toContain('Edited by you')
            ->not->toContain('data-clause-toggle')->not->toContain('<textarea')->not->toContain('Restore original wording');
    });

    it('says so when the Admin sends no clause templates', function () {
        T::fake([], array_replace(T::detail(), ['clause_templates' => []]));

        expect(test()->withSession(T::session())->get('/home')->getContent())->toContain('Terms of Trade clauses are not available');
    });
});

describe('saving clauses (PUT /draft form.clauses)', function () {
    function saveClauses(array $clauses, array $extra = [])
    {
        T::fake();
        test()->withSession(T::session())->get('/home');

        return test()->post('/tot/draft', ['form' => ['clauses' => $clauses] + $extra]);
    }

    it('sends the complete edited text as an object keyed by clause, with plain line breaks', function () {
        saveClauses(['termination' => "a) New wording.\r\nSecond line.\r\n\r\nb) More."])->assertRedirect(route('home'));

        $sent = T::sent('PUT', 'tots/'.T::TOT_ID.'/draft');
        expect($sent['form']['clauses'])->toBe(['termination' => "a) New wording.\nSecond line.\n\nb) More."]);
        expect(json_encode($sent['form']))->toContain('"clauses":{"termination"'); // an object, never a list
    });

    it('sends null (removes the edit) for an empty text, and passes the original text through for the Admin to drop', function () {
        saveClauses(['termination' => '', 'audit' => T::clauseTemplates()[10]['text']]);

        $sent = T::sent('PUT', 'tots/'.T::TOT_ID.'/draft')['form']['clauses'];
        expect($sent['termination'])->toBeNull()->and($sent['audit'])->toBe(T::clauseTemplates()[10]['text']);
    });

    it('sends only the clauses that were opened (disabled textareas are not posted) and never an empty clauses list', function () {
        T::fake();
        $this->withSession(T::session())->get('/home');

        $this->post('/tot/draft', ['form' => ['vendor_details' => ['vendor_name' => 'A']]]);

        expect(T::sent('PUT', 'tots/'.T::TOT_ID.'/draft')['form'])->not->toHaveKey('clauses');
    });

    it('ignores keys that are not clause keys and values that are not text', function () {
        saveClauses(['termination' => 'ok', 'Bad Key!' => 'x', '../etc' => 'x', 'audit' => ['not' => 'text']]);

        expect(T::sent('PUT', 'tots/'.T::TOT_ID.'/draft')['form']['clauses'])->toBe(['termination' => 'ok']);
    });

    it('shows the Admin error beside the clause and keeps what was typed, with the editor open', function () {
        T::fake(['PUT tots/'.T::TOT_ID.'/draft' => Http::response(['message' => 'x', 'errors' => ['clauses.termination' => ['The clause may not be longer than 20000 characters.']]], 422)]);
        $this->withSession(T::session())->get('/home');

        $this->from('/home')->post('/tot/draft', ['form' => ['clauses' => ['termination' => 'my long edit']]])->assertSessionHasErrors(['clauses.termination']);

        $card = clauseCard($this->get('/home')->getContent(), 'termination');
        expect($card)->toContain('The clause may not be longer than 20000 characters.')
            ->toMatch('/<textarea[^>]*name="form\[clauses\]\[termination\]"(?![^>]*disabled)[^>]*>my long edit<\/textarea>/')
            ->toContain('aria-expanded="true"')->not->toMatch('/<div class="clause-edit" id="clause-edit-termination"\s+hidden\s*>/');
    });

    it('lets the number fields of a clause be saved like any other field', function () {
        T::fake();
        $this->withSession(T::session())->get('/home');

        $this->post('/tot/draft', ['form' => ['business_terms' => ['refund_within_days' => '10', 'min_shelf_life_percent' => '75']]]);

        expect(T::sent('PUT', 'tots/'.T::TOT_ID.'/draft')['form']['business_terms'])->toEqual(['refund_within_days' => '10', 'min_shelf_life_percent' => '75']);
    });

    it('autosaves a clause through the JSON endpoint too', function () {
        T::fake();
        $this->withSession(T::session())->get('/home');

        $this->postJson('/tot/autosave', ['form' => ['clauses' => ['indemnity' => 'a) Edited.']]])->assertOk()->assertJson(['saved' => true]);

        expect(T::sent('PUT', 'tots/'.T::TOT_ID.'/draft')['form']['clauses'])->toBe(['indemnity' => 'a) Edited.']);
    });

    it('is submitted with the rest of the form (clauses are not mandatory)', function () {
        T::fake(['POST tots/'.T::TOT_ID.'/submit' => Http::response(['message' => 'ok', 'tot' => ['status' => 'pending_buyer'], 'warnings' => []])]);
        config(['vob.enforce_required' => false, 'vob.enforce_documents' => false]);

        $this->withSession(T::session())->post('/tot/submit', ['form' => ['clauses' => ['audit' => 'a) Edited audit.']]])->assertRedirect(route('home'));
        expect(T::sent('PUT', 'tots/'.T::TOT_ID.'/draft')['form']['clauses'])->toBe(['audit' => 'a) Edited audit.']);
    });
});

describe('saving and restoring a clause', function () {
    it('has a Save button beside Restore in every clause editor', function () {
        $html = clausePage();

        expect(substr_count($html, 'data-clause-save'))->toBe(substr_count($html, 'data-clause-restore'))
            ->and(substr_count($html, 'data-clause-save'))->toBeGreaterThanOrEqual(14);
    });

    it('saves and restores in place: no page reload, the clause is refreshed from the server', function () {
        $js = file_get_contents(public_path('js/tot-form.js'));

        expect($js)->not->toContain('location.reload')
            ->toContain('data-clause-save')->toContain('refreshClause')->toContain('saveClause');
    });
});

describe('the browser side', function () {
    it('has the pencil, restore and number-sync behaviour in the form script, with no inline code on the page', function () {
        $js = file_get_contents(public_path('js/tot-form.js'));
        expect($js)->toContain('data-clause-toggle')->toContain('data-clause-restore')->toContain('dataset.original')->toContain('[data-sync=')->toContain('scrollIntoView');

        $html = clausePage();
        expect($html)->not->toMatch('/<script(?![^>]*\ssrc=)/i')->not->toMatch('/\sstyle="/i')->not->toMatch('/\son(click|change|input)=/i');
    });
});

describe('reviewer comments per section (API_CONTRACT: Look for comment)', function () {
    function returnedDetail(array $over = []): array
    {
        $replace = array_intersect_key($over, array_flip(['reviewer_comments', 'flagged_sections'])); // lists are replaced, not merged
        $over = array_diff_key($over, $replace);

        return $replace + array_replace_recursive([
            'tot' => ['submitted_at' => '2026-10-21T05:00:00+00:00'],
            'reviewer_comments' => [[
                'status' => 'Pending with vendor', 'comments' => 'PAN and bank details do not match.', 'by' => 'Asha', 'at' => '2026-10-27T09:30:00+00:00',
                'sections' => [['key' => 'vendor_details', 'label' => 'Vendor Details'], ['key' => 'bank_account_details', 'label' => 'Bank Account Details']],
                'section_note' => "comment added for sections: 'Vendor Details', 'Bank Account Details'",
            ]],
            'flagged_sections' => [['key' => 'vendor_details', 'label' => 'Vendor Details'], ['key' => 'bank_account_details', 'label' => 'Bank Account Details'], ['key' => 'clause_termination', 'label' => 'Terms of Trade: 7. Termination']],
        ], $over);
    }

    it('shows the sentence under the comment', function () {
        $html = clausePage(returnedDetail());

        expect($html)->toContain('PAN and bank details do not match.')
            ->toContain(e("comment added for sections: 'Vendor Details', 'Bank Account Details'"));
    });

    it('shows nothing extra when the reviewer marked no section (section_note is null)', function () {
        $html = clausePage(returnedDetail(['reviewer_comments' => [['status' => 'Pending with vendor', 'comments' => 'PAN and bank details do not match.', 'by' => 'Asha', 'at' => '2026-10-27T09:30:00+00:00', 'section_note' => null, 'sections' => []]], 'flagged_sections' => []]));

        expect($html)->toContain('PAN and bank details do not match.')->not->toContain('comment added for section')->not->toContain('Comment added');
    });

    it('marks the flagged section titles with "Comment added" and gives every section card the id the Admin uses', function () {
        $html = clausePage(returnedDetail());

        expect($html)->toMatch('/<div class="card"\s+id="vendor_details"\s+data-flagged\s*>\s*<div class="card-header">Vendor Details <span class="badge badge-flag">Comment added<\/span>/')
            ->toMatch('/<div class="card"\s+id="bank_account_details"\s+data-flagged/')
            ->toMatch('/<div class="card clause-card" id="clause_termination"\s+data-clause\s+data-flagged/')
            ->and(clauseCard($html, 'termination'))->toContain('Comment added')
            ->and(clauseCard($html, 'audit'))->not->toContain('Comment added');
        // an unflagged section has its id but no marker
        expect($html)->toMatch('/<div class="card"\s+id="registration"\s*>\s*<div class="card-header">Registration<\/div>/');
        expect(substr_count($html, 'data-flagged'))->toBe(3);
    });

    it('uses the section keys listed in the contract for every card', function () {
        $html = clausePage();
        $expected = ['registration', 'tot_scope', 'vendor_details', 'contact_person', 'vendor_accounting_group', 'vendor_type', 'bank_account_details', 'regions_outlets',
            'accounting_information', 'vendor_payment_terms', 'lulu_receipt_terms', 'category', 'expenses_liability', 'business_development_terms', 'growth_rebate_slab',
            'margin_rebate', 'merchandising_manpower_support', 'payment_deduction', 'deliverables', 'single_point_of_contact', 'signatories_bda', 'annexure_a', 'annexure_b'];

        foreach ($expected as $key) {
            expect($html)->toContain('id="'.$key.'"');
        }
    });

    it('does not show the markers when the TOT is no longer with the vendor', function () {
        $html = clausePage(returnedDetail(['tot' => ['editable' => false, 'status' => 'pending_buyer', 'status_label' => 'Pending with buyer']]));

        expect($html)->not->toContain('data-flagged')->not->toContain('Comment added');
    });
});

describe('ClauseText', function () {
    it('splits on blank lines and keeps single line breaks inside the paragraph', function () {
        $paragraphs = ClauseText::paragraphs("a) One.\n\nb) Two\n(i) first\n(ii) second\r\n\r\n\r\nc) Three.");

        expect($paragraphs)->toHaveCount(3)
            ->and($paragraphs[1])->toBe([['text' => "b) Two\n(i) first\n(ii) second"]]);
    });

    it('turns only known {markers} into fields', function () {
        $paragraphs = ClauseText::paragraphs('within {refund_within_days} days, {unknown_thing} and {min_shelf_life_percent}%', []);

        expect($paragraphs[0])->toBe([['text' => 'within '], ['field' => 'refund_within_days'], ['text' => ' days, '], ['text' => '{unknown_thing}'], ['text' => ' and '], ['field' => 'min_shelf_life_percent'], ['text' => '%']]);
        expect(ClauseText::paragraphs('x {custom_field} y', ['custom_field'])[0][1])->toBe(['field' => 'custom_field']);
    });

    it('reports the fields in a text and their maximum', function () {
        expect(ClauseText::fieldsIn('a {refund_within_days} b'))->toBe(['refund_within_days'])
            ->and(ClauseText::max('min_shelf_life_percent'))->toBe(100)->and(ClauseText::max('refund_within_days'))->toBeNull();
    });
});

describe('mapper', function () {
    it('reads form.clauses (only edited clauses) and treats an empty list as no edits', function () {
        $mapper = new TotFormMapper;

        expect($mapper->values(['clauses' => ['termination' => 'x']])['clauses'])->toBe(['termination' => 'x'])
            ->and($mapper->values(['clauses' => []]))->not->toHaveKey('clauses');
    });
});
