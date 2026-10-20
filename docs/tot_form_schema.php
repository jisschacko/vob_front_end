<?php

/*
| Schema of the vendor TOT form (docs/TOT_Main_Form.html). One definition drives: validation, who may edit which
| field, loading/saving, and the lists of locked / mandatory fields sent to the Vendor Onboarding Platform.
| Plain data only (no closures/objects) so `php artisan config:cache` works.
|
| Section kinds
|   single : one row per TOT                     (payload: object)
|   keyed  : several rows identified by a key    (payload: list of objects, each carrying the key)
|   list   : free list of rows                   (payload: list of objects)
|   pivot  : list of master codes                (payload: list of strings)
|
| Field keys
|   type      string | int | bool | date | decimal     (cast on save/load)
|   rules     Laravel validation rules (draft saves are lenient: nullable + format only)
|   by        'buyer' = only buyer/Admin may edit; the vendor sees it greyed out. Default: vendor + buyer.
|   encrypted stored encrypted at rest
|   ref       master lookup by code: [table, fk column]
*/

$yesNo = 'in:Yes,No,NA';
// One list for Payment Mode, Lulu Receipt Mode and Deduction Mode
$modes = 'in:RTGS Payment,ePayment,Bank Transfer,Manual Check,RTGS/NEFT Payment H2H';
$party = 'in:Lulu,Vendor,NA';

$str = fn (int $max = 200) => ['type' => 'string', 'rules' => ['string', "max:$max"]];
$int = fn (int $max = 999) => ['type' => 'int', 'rules' => ['integer', 'min:0', "max:$max"]];
$dec = fn () => ['type' => 'decimal', 'rules' => ['numeric', 'min:0', 'max:999999999999']];
$date = fn () => ['type' => 'date', 'rules' => ['date_format:Y-m-d']];
$enum = fn (string $in) => ['type' => 'string', 'rules' => ['string', $in]];
$phone = ['type' => 'string', 'rules' => ['string', 'regex:/^\+?[0-9]{7,15}$/']];
$email = ['type' => 'string', 'rules' => ['string', 'email', 'max:200']];

return [

    'sections' => [

        'vendor_details' => [
            'kind' => 'single', 'table' => 'tot_vendor_details',
            'fields' => [
                'registration_type' => $enum('in:New,Renewal'),
                'agreement_no' => $str(),
                'scope_type' => $enum('in:PAN India,Regional,State'),
                'scope_state' => $str(),
                'purchase_org' => ['type' => 'string', 'by' => 'buyer', 'ref' => ['purchase_orgs', 'purchase_org_id'],
                    'rules' => ['string', 'exists:purchase_orgs,code,deleted_at,NULL']],
                'vendor_accounting_group' => ['type' => 'string', 'by' => 'buyer', 'ref' => ['vendor_accounting_groups', 'vendor_accounting_group_id'],
                    'rules' => ['string', 'exists:vendor_accounting_groups,code,deleted_at,NULL']],
                'vendor_name' => $str(),
                'vendor_code' => $str(),
                'brand' => $str(),
                'parent_code' => $str(),
                'address' => $str(500),
                'city' => $str(100),
                'state' => ['type' => 'string', 'ref' => ['states', 'state_id'], 'rules' => ['string', 'exists:states,code,deleted_at,NULL']],
                'pin' => ['type' => 'string', 'rules' => ['string', 'regex:/^[0-9]{6}$/']],
                'pan_no' => ['type' => 'string', 'encrypted' => true, 'rules' => ['string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/']],
                'gst_type' => $enum('in:GST Registered,GST Unregistered,GST Compounding'),
                'gst_regn_no' => ['type' => 'string', 'encrypted' => true, 'rules' => ['string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/']],
                'msme_type' => $enum('in:MSME Registered,MSME Unregistered'),
                'msme_regn_no' => ['type' => 'string', 'encrypted' => true, 'rules' => ['string', 'max:50']],
                'entity_type' => $enum('in:Company,Partnership,Proprietorship,Hindu Undivided Family,Society,PSU/ Govt bodies,Others'),
            ],
        ],

        // Company codes are chosen by the buyer (the vendor sees them greyed out).
        'company_codes' => [
            'kind' => 'pivot', 'table' => 'tot_company_codes', 'by' => 'buyer',
            'ref' => ['company_codes', 'company_code_id'],
            'rules' => ['exists:company_codes,code,deleted_at,NULL'],
        ],

        'contacts' => [
            'kind' => 'keyed', 'table' => 'tot_contacts', 'key' => 'type', 'keys' => ['sales', 'finance', 'logistics'],
            'fields' => ['name' => $str(), 'phone' => $phone, 'email' => $email],
        ],

        'bank' => [
            'kind' => 'single', 'table' => 'tot_bank_details',
            // changing any of these invalidates an earlier bank verification
            'verification_fields' => ['account_name', 'account_no', 'ifsc'],
            'fields' => [
                'account_name' => $str(),
                'bank_name_address' => $str(500),
                'account_no' => ['type' => 'string', 'encrypted' => true, 'rules' => ['string', 'regex:/^[0-9A-Za-z]{6,34}$/']],
                'ifsc' => ['type' => 'string', 'rules' => ['string', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/']],
                'validation_failed_override' => ['type' => 'bool', 'rules' => ['boolean']],
            ],
        ],

        'outlets' => [
            'kind' => 'list', 'table' => 'tot_outlets', 'max' => 50,
            'fields' => [
                'state' => ['type' => 'string', 'ref' => ['states', 'state_id'], 'rules' => ['string', 'exists:states,code,deleted_at,NULL']],
                'is_all' => ['type' => 'bool', 'rules' => ['boolean']],
                'store_1' => $str(), 'store_2' => $str(), 'store_3' => $str(), 'store_4' => $str(),
                'store_5' => $str(), 'store_6' => $str(), 'store_7' => $str(),
            ],
        ],

        'payment_terms' => [
            'kind' => 'single', 'table' => 'tot_payment_terms',
            'fields' => [
                'accounting_info_type' => $enum('in:Monthly,Quarterly,Half Yearly,Yearly'),
                'reconciliation_type' => ['type' => 'string', 'by' => 'buyer', 'rules' => ['string',
                    'in:Merchandise Payable,Merchandise Payable Import,Non-Merchandise Payable,Tenant Payable,Consignment /SOA Vendor Payable,Project Payable']],
                'days_from_statement' => $int(), 'days_from_grn' => $int(), 'y_days' => $int(),
                'z_days' => $int(), 'b_days' => $int(), 'sor_days' => $int(),
                'payment_mode' => $enum($modes),
                'apply_15_day_rule' => ['type' => 'bool', 'rules' => ['boolean']],
                'bank_guarantee_value' => $str(), 'bank_guarantee_date' => $date(), 'bank_guarantee_expiry' => $date(),
                'security_cheque_value' => $str(), 'security_cheque_date' => $date(), 'security_cheque_expiry' => $date(),
            ],
        ],

        'trade_terms' => [
            'kind' => 'single', 'table' => 'tot_trade_terms',
            'fields' => [
                'receipt_terms' => $str(),
                'receipt_mode' => $enum($modes),
                'transportation' => $enum($party), 'loading_unloading' => $enum($party), 'pilferage' => $enum($party),
                'ullage' => $enum($party), 'packing_forwarding' => $enum($party), 'insurance_in_transit' => $enum($party),
                'insurance_in_store' => $enum($party), 'vendor_returns_transportation' => $enum($party),
                'returnable_goods' => $enum($yesNo),
                'return_type' => $enum('in:B to B Invoice,Delivery Challan (Debit Note),Credit Note,NA'),
            ],
        ],

        'categories' => [
            'kind' => 'pivot', 'table' => 'tot_categories', 'ref' => ['categories', 'category_id'],
            'rules' => ['exists:categories,code'],
        ],

        'business_terms' => [
            'kind' => 'single', 'table' => 'tot_business_terms',
            'fields' => [
                'period_from' => $date(), 'period_to' => $date(),
                'vendor_listing_fee' => $str(1000), 'brand_listing_fee' => $str(1000), 'sku_listing_fee' => $str(1000),
                'base_margin_regular' => $str(1000), 'base_margin_eoss' => $str(1000),
                'unloading_charges' => $str(1000), 'pilferage_terms' => $str(1000), 'advertisement_support' => $str(1000),
                'new_store_support_daily' => $str(1000), 'new_store_support_hyper' => $str(1000),
                'deduction_mode' => $enum($modes),
                'base_target' => $enum('in:All Stores,Online,Corporate,New Store'),
                'promoters_count' => $int(100000),
                'promoters_sharing_value' => $str(1000),
                'promoters_payroll' => $enum('in:LuLu,Vendor/Brand'),
                'promoters_uniform_cost' => $enum('in:LuLu,Vendor/Brand'),
                'payment_deduction' => $enum('in:Debit,Cheque/RTGS'),
                'margin_others' => $str(1000),
                'refund_within_days' => $int(),
                'min_shelf_life_percent' => $int(100),
                'grievance_attention' => $str(),
                'grievance_address' => $str(500),
                'grievance_email' => $email,
            ],
        ],

        'rebate_lines' => [
            'kind' => 'keyed', 'table' => 'tot_rebate_lines', 'key' => ['group', 'kind'],
            'keys' => [
                'rebate:fixed_rebate', 'rebate:primary_visibility', 'rebate:secondary_visibility', 'rebate:logistics_rebate',
                'stock_correction:stock_correction',
                'other_terms:central_payment', 'other_terms:early_payment', 'other_terms:data_sharing', 'other_terms:ullage',
            ],
            'fields' => [
                'pct_of_purchase' => $dec(), 'on_invoice' => $dec(), 'off_invoice' => $dec(), 'monthly' => $dec(),
                'quarterly' => $dec(), 'half_yearly' => $dec(), 'yearly' => $dec(),
            ],
        ],

        'growth_slabs' => [
            'kind' => 'keyed', 'table' => 'tot_growth_slabs', 'key' => 'slab_no', 'keys' => ['1', '2', '3', '4', '5'],
            'fields' => ['target' => $dec(), 'rebate' => $dec()],
        ],

        'margins' => [
            'kind' => 'list', 'table' => 'tot_margins', 'max' => 30,
            'fields' => ['material_category_brand' => $str(), 'front_end_margin' => $str(), 'back_end_margin' => $str()],
        ],

        'deliverables' => [
            'kind' => 'keyed', 'table' => 'vendor_deliverables', 'key' => 'asset', 'timestamps' => true,
            'ref' => ['deliverable_assets', 'deliverable_asset_id'],
            'fields' => [
                'numbers' => $str(), 'brand' => $str(), 'paid_total' => $str(), 'duration' => $str(),
                'payment_terms' => $enum('in:Debit,Cheque/RTGS'),
            ],
        ],

        'pocs' => [
            'kind' => 'keyed', 'table' => 'tot_pocs', 'key' => 'dept', 'keys' => ['Buying', 'Logistics', 'Finance', 'GST'],
            'fields' => [
                'first_poc' => $str(), 'first_mobile' => $phone, 'email' => $email,
                'second_poc' => $str(), 'second_mobile' => $phone, 'second_email' => $email,
            ],
        ],

        'signatories' => [
            'kind' => 'keyed', 'table' => 'tot_signatories', 'key' => 'kind', 'keys' => ['bda_lulu', 'bda_vendor'], // the Terms of Trade signatures are e-sign anchors now, not inputs
            'fields' => [
                'name' => $str(), 'designation' => $str(), 'contact_no' => $phone,
                'signed_date' => $date(), 'company_stamp' => $str(),
            ],
        ],
    ],

    /*
    | Mandatory before the VENDOR may submit (18 fields agreed with the business). Paths are "section.field"
    | or "section.<key>.field" for keyed sections. GST Regn. No is not required for GST Unregistered vendors.
    */
    'vendor_required' => [
        'vendor_details.vendor_name', 'vendor_details.address', 'vendor_details.city', 'vendor_details.state',
        'vendor_details.pin', 'vendor_details.gst_regn_no', 'vendor_details.pan_no', 'vendor_details.gst_type',
        'contacts.sales.phone', 'contacts.sales.email', 'contacts.finance.phone', 'contacts.finance.email',
        'bank.account_name', 'bank.bank_name_address', 'bank.account_no', 'bank.ifsc',
        'payment_terms.terms', 'payment_terms.payment_mode',
    ],

    // "Payment Terms" is satisfied by at least one of these day counts.
    'payment_terms_fields' => ['days_from_statement', 'days_from_grn', 'y_days', 'z_days', 'b_days', 'sor_days'],

    /*
    | Mandatory before the BUYER may approve at "Pending with buyer" (22 fields). Used in Phase 5.
    */
    'buyer_required' => [
        'company_codes', 'vendor_details.purchase_org', 'vendor_details.vendor_name', 'vendor_details.address',
        'vendor_details.city', 'vendor_details.state', 'vendor_details.pin', 'vendor_details.gst_regn_no',
        'vendor_details.pan_no', 'vendor_details.gst_type', 'contacts.sales.phone', 'contacts.sales.email',
        'contacts.finance.phone', 'contacts.finance.email', 'vendor_details.vendor_accounting_group',
        'bank.account_name', 'bank.bank_name_address', 'bank.account_no', 'bank.ifsc',
        'payment_terms.reconciliation_type', 'payment_terms.terms', 'payment_terms.payment_mode',
    ],

    // Documents the vendor must attach before submitting (type => condition).
    'documents' => [
        'types' => ['pan_certificate', 'gst_certificate', 'msme_certificate', 'cancelled_cheque', 'rtgs_form', 'board_resolution'],
        'always_required' => ['pan_certificate', 'cancelled_cheque', 'rtgs_form'],
        'gst_certificate_required_when' => 'GST Registered',
        'msme_certificate_required_when' => 'MSME Registered',
    ],

    // Signature anchors printed on the TOT PDF (E-Sign section) for the eMudhra step, in signing order.
    'esign_anchors' => [
        'vendor' => 'V01',
        'central_buyer' => 'L01',
        'manager_central_buying' => 'L02',
        'gm_buying' => 'L03',
    ],

    // Renewal prefill (copied from the vendor's previous TOT the first time a renewal is opened).
    'prefill' => [
        'vendor_details' => ['registration_type', 'agreement_no', 'purchase_org', 'vendor_name', 'vendor_code', 'brand', 'parent_code',
            'address', 'city', 'state', 'pin', 'gst_regn_no', 'pan_no', 'msme_regn_no', 'gst_type', 'msme_type'],
        'bank' => ['account_name', 'bank_name_address', 'account_no', 'ifsc'],
        'company_codes' => true,
    ],
];
