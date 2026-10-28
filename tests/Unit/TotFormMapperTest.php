<?php

use App\Services\Tot\TotFormMapper;
use App\Services\Tot\TotLayout;
use Tests\Support\TotFixtures;

beforeEach(fn () => $this->mapper = new TotFormMapper);

it('reads the API form into path values: single, keyed (incl. group:kind), list and pivot sections', function () {
    $v = $this->mapper->values(TotFixtures::detail()['form']);

    expect($v['vendor_details']['vendor_name'])->toBe('Acme Foods Pvt Ltd')
        ->and($v['contacts']['sales']['phone'])->toBe('+919999999999')
        ->and($v['rebate_lines']['rebate:fixed_rebate']['on_invoice'])->toBe('2.5')
        ->and($v['growth_slabs']['1']['target'])->toBe('100')
        ->and($v['deliverables']['end_cap']['numbers'])->toBe('3')
        ->and($v['outlets'][0]['state'])->toBe('KL')
        ->and($v['payment_terms']['apply_15_day_rule'])->toBe('Yes')   // booleans are shown as Yes / No
        ->and($v['bank']['validation_failed_override'])->toBe('No')
        ->and($v['categories'])->toBe(['food'])
        ->and($v['company_codes'])->toBe(['9000', '9001']);
});

it('builds the draft body: blanks become null, Yes/No become booleans, rows carry their keys', function () {
    $payload = $this->mapper->payload([
        'vendor_details' => ['vendor_name' => ' Acme ', 'brand' => '', 'bogus_field' => 'x'],
        'payment_terms' => ['apply_15_day_rule' => 'No', 'days_from_grn' => '45', 'payment_mode' => ''],
        'bank' => ['validation_failed_override' => '1'],
        'contacts' => ['sales' => ['name' => 'Sam', 'phone' => ''], 'finance' => ['email' => 'f@acme.test'], 'unknown' => ['name' => 'x']],
        'rebate_lines' => ['rebate:fixed_rebate' => ['on_invoice' => '2.5'], 'other_terms:ullage' => ['pct_of_purchase' => '1']],
        'growth_slabs' => ['2' => ['target' => '500', 'rebate' => '']],
        'deliverables' => ['end_cap' => ['numbers' => '2'], 'not_an_asset' => ['numbers' => '9']],
        'categories' => ['food' => 'Yes', 'fv' => 'No', 'toys' => ''],
        'bogus_section' => ['a' => 'b'],
    ], [], [], ['end_cap', 'pallet'])['form'];

    expect($payload['vendor_details'])->toBe(['vendor_name' => 'Acme', 'brand' => null])
        ->and($payload['payment_terms'])->toEqual(['apply_15_day_rule' => false, 'days_from_grn' => '45', 'payment_mode' => null])
        ->and($payload['bank'])->toBe(['validation_failed_override' => true])
        ->and($payload['contacts'])->toBe([
            ['type' => 'sales', 'name' => 'Sam', 'phone' => null],
            ['type' => 'finance', 'email' => 'f@acme.test'],
        ])
        ->and($payload['rebate_lines'])->toBe([
            ['group' => 'rebate', 'kind' => 'fixed_rebate', 'on_invoice' => '2.5'],
            ['group' => 'other_terms', 'kind' => 'ullage', 'pct_of_purchase' => '1'],
        ])
        ->and($payload['growth_slabs'])->toBe([['slab_no' => 2, 'target' => '500', 'rebate' => null]])
        ->and($payload['deliverables'])->toBe([['asset' => 'end_cap', 'numbers' => '2']])
        ->and($payload['categories'])->toBe(['food'])
        ->and($payload)->not->toHaveKey('bogus_section');
});

it('never sends locked fields or sections', function () {
    $locked = ['company_codes', 'purchase_orgs', 'payment_terms.reconciliation_type'];

    $payload = $this->mapper->payload([
        'company_codes' => ['9999'],
        'purchase_orgs' => ['9000'],
        'vendor_details' => ['vendor_name' => 'Acme'],
        'payment_terms' => ['reconciliation_type' => 'Tenant Payable', 'payment_mode' => 'ePayment'],
    ], $locked, [], [])['form'];

    expect($payload)->not->toHaveKey('company_codes')
        ->and($payload['vendor_details'])->toBe(['vendor_name' => 'Acme'])
        ->and($payload['payment_terms'])->toEqual(['payment_mode' => 'ePayment']);
});

it('never sends the buyer-only fields even when the API reports no locked fields', function () {
    $payload = $this->mapper->payload([
        'company_codes' => ['9999'],
        'purchase_orgs' => ['9000'],
        'vendor_details' => [
            'vendor_name' => 'Acme', 'vendor_accounting_group' => 'X', 'agreement_no' => 'AG-1', 'vendor_code' => 'V-1',
        ],
        'payment_terms' => ['reconciliation_type' => 'Tenant Payable', 'payment_mode' => 'ePayment'],
    ], [], [], [])['form'];

    expect($payload)->not->toHaveKey('company_codes')->and($payload)->not->toHaveKey('purchase_orgs')
        ->and($payload['vendor_details'])->toBe(['vendor_name' => 'Acme'])
        ->and($payload['payment_terms'])->toEqual(['payment_mode' => 'ePayment']);
});

it('drops blank rows from replaced lists but still sends an empty list so it can be cleared', function () {
    $payload = $this->mapper->payload([
        'outlets' => [['state' => 'KL', 'is_all' => '0', 'store_1' => ''], ['state' => '', 'is_all' => '0', 'store_1' => ''], []],
        'margins' => [['material_category_brand' => '', 'front_end_margin' => '', 'back_end_margin' => '']],
    ], [], [], [])['form'];

    expect($payload['outlets'])->toEqual([['state' => 'KL', 'is_all' => false, 'store_1' => null]])
        ->and($payload['margins'])->toBe([]);
});

it('keeps a stored masked value when the vendor did not retype it, but clears other blanks', function () {
    $payload = $this->mapper->payload([
        'bank' => ['account_no' => '', 'account_name' => ''],
    ], [], ['bank.account_no'], [])['form'];

    expect($payload['bank'])->toBe(['account_name' => null]);

    $retyped = $this->mapper->payload(['bank' => ['account_no' => '999988887777']], [], ['bank.account_no'], [])['form'];
    expect($retyped['bank'])->toBe(['account_no' => '999988887777']);
});

it('maps API error indexes back to the page field paths', function () {
    $payload = $this->mapper->payload([
        'contacts' => ['sales' => ['phone' => '1'], 'finance' => ['phone' => '2']],
    ], [], [], []);

    $errors = $this->mapper->errorsByPath([
        'contacts.1.phone' => ['The phone format is invalid.'],
        'vendor_details.pan_no' => ['Bad PAN'],
        'outlets.0.state' => ['Unknown state'],
    ], $payload['keyed']);

    expect($errors)->toBe([
        'contacts.finance.phone' => 'The phone format is invalid.',
        'vendor_details.pan_no' => 'Bad PAN',
        'outlets.0.state' => 'Unknown state',
    ]);
});

it('lets a locked entry cover everything below it', function () {
    expect(TotFormMapper::isLocked('company_codes', ['company_codes']))->toBeTrue()
        ->and(TotFormMapper::isLocked('company_codes.x', ['company_codes']))->toBeTrue()
        ->and(TotFormMapper::isLocked('company_codes_extra', ['company_codes']))->toBeFalse()
        ->and(TotFormMapper::isLocked('vendor_details.vendor_name', ['vendor_details.vendor_accounting_group']))->toBeFalse();
});

it('has a layout whose every field belongs to a known API section field', function () {
    $sections = TotLayout::sections();
    $paths = [];
    $collect = function (array $item) use (&$paths) {
        foreach (($item['blocks'] ?? []) as $b) {
            match ($b[0]) {
                'grid' => array_push($paths, ...array_column($b[2], 'path')),
                'table' => array_push($paths, ...collect($b[2])->flatten(1)->filter(fn ($c) => is_array($c))->pluck('path')->all()),
                default => null,
            };
        }
    };
    foreach (TotLayout::cards() as $card) {
        $collect($card);
    }

    expect($paths)->not->toBeEmpty();
    foreach ($paths as $path) {
        $parts = explode('.', $path);
        $section = $parts[0];
        $field = end($parts);
        if (in_array($section, ['company_codes', 'purchase_orgs'], true)) { // pivot lists have no fields
            continue;
        }
        expect($sections)->toHaveKey($section);
        expect(array_key_exists($field, $sections[$section]['fields']))->toBeTrue("layout field $path is not an API field");
    }
});
