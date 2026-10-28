<?php

namespace App\Services\Tot;

/**
 * Presentation of docs/TOT_Main_Form.html: which cards, fields, labels and widgets the vendor sees, and how each API section
 * is shaped (docs/API_CONTRACT.md section 5, "Section kinds").
 *
 * What is NOT defined here, because it comes from the Admin API on every page load: which fields are locked (`locked_fields`),
 * which are mandatory (`required_fields`), the dropdown masters (`/masters`) and every format rule (the Admin validates formats on each
 * draft save and returns the errors, which the form shows next to the field).
 */
final class TotLayout
{
    /** One list for Payment Mode, Lulu Receipt Mode and Deduction Mode (API_CONTRACT section 8). */
    private const MODES = ['RTGS Payment', 'ePayment', 'Bank Transfer', 'Manual Check', 'RTGS/NEFT Payment H2H'];

    private const PARTY = ['Lulu', 'Vendor', 'NA'];

    /**
     * API sections: kind, the key field(s) of keyed sections, allowed keys, and the type of every field
     * (text|number|date|bool). Drives both the payload sent to PUT /draft and the values read from GET /tots/{id}.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function sections(): array
    {
        $text = fn (string ...$names) => array_fill_keys($names, 'text');
        $num = fn (string ...$names) => array_fill_keys($names, 'number');
        $date = fn (string ...$names) => array_fill_keys($names, 'date');

        return [
            'vendor_details' => ['kind' => 'single', 'fields' => $text(
                'registration_type', 'agreement_no', 'scope_type', 'scope_state', 'vendor_accounting_group', 'vendor_name',
                'vendor_code', 'brand', 'parent_code', 'address', 'city', 'state', 'pin', 'pan_no', 'gst_type', 'gst_regn_no',
                'msme_type', 'msme_regn_no', 'entity_type')],
            'company_codes' => ['kind' => 'pivot'],
            'purchase_orgs' => ['kind' => 'pivot'], // several codes, buyer-only like company_codes
            'contacts' => ['kind' => 'keyed', 'key' => ['type'], 'keys' => ['sales', 'finance', 'logistics'],
                'fields' => $text('name', 'phone', 'email')],
            'bank' => ['kind' => 'single', 'fields' => $text('account_name', 'bank_name_address', 'account_no', 'ifsc') + ['validation_failed_override' => 'bool']],
            'outlets' => ['kind' => 'list', 'fields' => $text('state', 'store_1', 'store_2', 'store_3', 'store_4', 'store_5', 'store_6', 'store_7') + ['is_all' => 'bool']],
            'payment_terms' => ['kind' => 'single', 'fields' => $text('accounting_info_type', 'reconciliation_type', 'payment_mode', 'bank_guarantee_value', 'security_cheque_value')
                + $num('days_from_statement', 'days_from_grn', 'y_days', 'z_days', 'b_days', 'sor_days')
                + $date('bank_guarantee_date', 'bank_guarantee_expiry', 'security_cheque_date', 'security_cheque_expiry')
                + ['apply_15_day_rule' => 'bool']],
            'trade_terms' => ['kind' => 'single', 'fields' => $text(
                'receipt_terms', 'receipt_mode', 'transportation', 'loading_unloading', 'pilferage', 'ullage', 'packing_forwarding',
                'insurance_in_transit', 'insurance_in_store', 'vendor_returns_transportation', 'returnable_goods', 'return_type')],
            'categories' => ['kind' => 'pivot'],
            'business_terms' => ['kind' => 'single', 'fields' => $text(
                'vendor_listing_fee', 'brand_listing_fee', 'sku_listing_fee', 'base_margin_regular', 'base_margin_eoss', 'unloading_charges',
                'pilferage_terms', 'advertisement_support', 'new_store_support_daily', 'new_store_support_hyper', 'deduction_mode', 'base_target',
                'promoters_sharing_value', 'promoters_payroll', 'promoters_uniform_cost', 'payment_deduction', 'margin_others',
                'grievance_attention', 'grievance_address', 'grievance_email')
                + $date('period_from', 'period_to')
                + $num('promoters_count', 'refund_within_days', 'min_shelf_life_percent')],
            'rebate_lines' => ['kind' => 'keyed', 'key' => ['group', 'kind'], 'keys' => [
                'rebate:fixed_rebate', 'rebate:primary_visibility', 'rebate:secondary_visibility', 'rebate:logistics_rebate',
                'stock_correction:stock_correction',
                'other_terms:central_payment', 'other_terms:early_payment', 'other_terms:data_sharing', 'other_terms:ullage',
            ], 'fields' => $num('pct_of_purchase', 'on_invoice', 'off_invoice', 'monthly', 'quarterly', 'half_yearly', 'yearly')],
            'growth_slabs' => ['kind' => 'keyed', 'key' => ['slab_no'], 'keys' => ['1', '2', '3', '4', '5'], 'int_key' => true,
                'fields' => $num('target', 'rebate')],
            'margins' => ['kind' => 'list', 'fields' => $text('material_category_brand', 'front_end_margin', 'back_end_margin')],
            'deliverables' => ['kind' => 'keyed', 'key' => ['asset'], 'keys' => 'master:deliverable_assets',
                'fields' => $text('numbers', 'brand', 'paid_total', 'duration', 'payment_terms')],
            'pocs' => ['kind' => 'keyed', 'key' => ['dept'], 'keys' => ['Buying', 'Logistics', 'Finance', 'GST'],
                'fields' => $text('first_poc', 'first_mobile', 'email', 'second_poc', 'second_mobile', 'second_email')],
            'clauses' => ['kind' => 'clauses'],
            'signatories' => ['kind' => 'keyed', 'key' => ['kind'], 'keys' => ['bda_lulu', 'bda_vendor'],
                'fields' => $text('name', 'designation', 'contact_no', 'signed_date', 'company_stamp')],
        ];
    }

    /** Document slots: type => [label, help]. */
    public static function documents(): array
    {
        return [
            'pan_certificate' => ['PAN Certificate'],
            'gst_certificate' => ['GST Certificate'],
            'msme_certificate' => ['MSME Certificate'],
            'cancelled_cheque' => ['Cancelled Cheque / Bank Letter'],
            'rtgs_form' => ['RTGS Form', 'Download the sample, fill it in, then upload the completed form here.'],
            'board_resolution' => ['Board Resolution / Authorisation Letter', "Attach the vendor's board resolution or authorisation letter naming its signatory for this agreement."],
        ];
    }

    /**
     * Cards in page order. A card has a title and blocks; other items are dividers or raw views.
     *
     * @return list<array<string,mixed>>
     */
    public static function cards(): array
    {
        $modes = self::MODES;
        $abc = fn (string $path, string $label, array $options = []) => self::field($path, $label, 'select', $options + ['options' => self::PARTY]);

        return [
            ['id' => 'registration', 'title' => 'Registration', 'blocks' => [['grid', 2, [
                self::field('vendor_details.registration_type', 'Registration Type', 'select', ['options' => ['New', 'Renewal'], 'help' => 'Whether this is a brand-new vendor agreement or a renewal of an existing one.']),
                self::field('vendor_details.agreement_no', 'Vendor Agreement No', 'text', ['help' => 'Unique reference number for this vendor agreement.']),
            ]]]],

            ['id' => 'tot_scope', 'title' => 'TOT Scope', 'blocks' => [['grid', 2, [
                self::field('vendor_details.scope_type', 'Scope Type', 'select', ['options' => ['PAN India', 'Regional', 'State'], 'help' => 'Whether this agreement applies across India, to a region, or a single state.']),
                self::field('vendor_details.scope_state', 'State / Region', 'scope_state', ['help' => 'The state when Scope Type is State, the region when it is Regional. Not needed for PAN India.']),
                ['type' => 'company_codes', 'path' => 'company_codes', 'label' => 'Company Code', 'help' => 'SAP company codes linked to this agreement.'],
                ['type' => 'purchase_orgs', 'path' => 'purchase_orgs', 'label' => 'Purchase Organization', 'help' => 'Purchase organizations linked to this agreement.'],
            ]]]],

            ['id' => 'vendor_details', 'title' => 'Vendor Details', 'blocks' => [
                ['grid', 2, [
                    self::field('vendor_details.vendor_name', 'Vendor Name'),
                    self::field('vendor_details.vendor_code', 'Vendor Code'),
                    self::field('vendor_details.brand', 'Brand (If any)'),
                    self::field('vendor_details.parent_code', 'Parent Code'),
                    self::field('vendor_details.address', 'Address', 'text', ['maxlength' => 500]),
                    self::field('vendor_details.city', 'City', 'text', ['maxlength' => 100]),
                    self::field('vendor_details.state', 'State', 'select', ['master' => 'states']),
                    self::field('vendor_details.pin', 'PIN', 'line'),
                    self::field('vendor_details.pan_no', 'PAN No', 'line'),
                    self::field('vendor_details.gst_type', 'GST Type', 'select', ['options' => ['GST Registered', 'GST Unregistered', 'GST Compounding']]),
                    self::field('vendor_details.gst_regn_no', 'GST Regn. No', 'line'),
                    self::field('vendor_details.msme_type', 'MSME Type', 'select', ['options' => ['MSME Registered', 'MSME Unregistered'], 'help' => 'If MSME Registered, the vendor is eligible for payment within 45 days from GRN.']),
                    self::field('vendor_details.msme_regn_no', 'MSME Regn No.', 'line'),
                ]],
                ['note', 'A copy of the PAN, GST and MSME certificate is mandatory if registered.'],
                ['documents', ['pan_certificate', 'gst_certificate', 'msme_certificate']],
            ]],

            ['id' => 'contact_person', 'title' => 'Contact Person', 'blocks' => [['grid', 3, [
                self::field('contacts.sales.name', 'Sales Contact Name'), self::field('contacts.sales.phone', 'Sales Contact No', 'tel'), self::field('contacts.sales.email', 'Sales Email', 'email'),
                self::field('contacts.finance.name', 'Finance Contact Name'), self::field('contacts.finance.phone', 'Finance Contact No', 'tel'), self::field('contacts.finance.email', 'Finance Email', 'email'),
                self::field('contacts.logistics.name', 'Logistics Contact Name'), self::field('contacts.logistics.phone', 'Logistics Contact No', 'tel'), self::field('contacts.logistics.email', 'Logistics Email', 'email'),
            ]]]],

            ['id' => 'vendor_accounting_group', 'title' => 'Vendor Accounting Group', 'blocks' => [['grid', 2, [
                self::field('vendor_details.vendor_accounting_group', 'Vendor Accounting Group Type', 'select', ['master' => 'vendor_accounting_groups']),
            ]]]],

            ['id' => 'vendor_type', 'title' => 'Vendor Type', 'blocks' => [['grid', 2, [
                self::field('vendor_details.entity_type', 'Vendor Type', 'select', ['options' => ['Company', 'Partnership', 'Proprietorship', 'Hindu Undivided Family', 'Society', 'PSU/ Govt bodies', 'Others']]),
            ]]]],

            ['id' => 'bank_account_details', 'title' => 'Bank Account Details', 'blocks' => [
                ['grid', 2, [
                    self::field('bank.account_name', 'Account Name'),
                    self::field('bank.bank_name_address', 'Bank Name and Address', 'text', ['maxlength' => 500]),
                    self::field('bank.account_no', 'Account No', 'line'),
                    self::field('bank.ifsc', 'IFS Code', 'line'),
                ]],
                ['bank_status'],
                ['grid', 1, [
                    self::field('bank.validation_failed_override', 'For failed validations.', 'check', ['help' => 'Check this to proceed on manual vendor approval if automated bank account validation fails or is unavailable.']),
                ]],
                ['note', 'A copy of the cancelled cheque, bank letter, and RTGS form is mandatory.'],
                ['documents', ['cancelled_cheque', 'rtgs_form']],
            ]],

            ['id' => 'regions_outlets', 'title' => 'Regions & Outlets', 'blocks' => [
                ['note', 'Add one row per state. Store codes are optional; tick "All" for every state.'],
                ['list_table', 'outlets', 1, ['State', 'All', 'Store 1', 'Store 2', 'Store 3', 'Store 4', 'Store 5', 'Store 6', 'Store 7'], [
                    'state' => ['select', ['master' => 'states']], 'is_all' => ['check'],
                    'store_1' => ['text'], 'store_2' => ['text'], 'store_3' => ['text'], 'store_4' => ['text'],
                    'store_5' => ['text'], 'store_6' => ['text'], 'store_7' => ['text'],
                ]],
            ]],

            ['id' => 'accounting_information', 'title' => 'Accounting Information', 'blocks' => [['grid', 2, [
                self::field('payment_terms.accounting_info_type', 'Accounting Information Type', 'select', ['options' => ['Monthly', 'Quarterly', 'Half Yearly', 'Yearly'], 'help' => 'Frequency of financial reconciliation.']),
                self::field('payment_terms.reconciliation_type', 'Reconciliation of Accounts Type', 'select', ['options' => ['Merchandise Payable', 'Merchandise Payable Import', 'Non-Merchandise Payable', 'Tenant Payable', 'Consignment /SOA Vendor Payable', 'Project Payable']]),
            ]]]],

            ['id' => 'vendor_payment_terms', 'title' => 'Vendor Payment Terms', 'blocks' => [
                ['payment_terms_note'],
                ['grid', 2, [
                    self::field('payment_terms.days_from_statement', 'Days from Statement / Month End', 'number'),
                    self::field('payment_terms.days_from_grn', 'Days from GRN / IML', 'number'),
                    self::field('payment_terms.y_days', 'Y (days)', 'number'),
                    self::field('payment_terms.z_days', 'Z (days)', 'number'),
                    self::field('payment_terms.b_days', 'B (days)', 'number'),
                    self::field('payment_terms.sor_days', 'SOR (days)', 'number'),
                    self::field('payment_terms.payment_mode', 'Payment Mode', 'select', ['options' => $modes]),
                    self::field('payment_terms.apply_15_day_rule', 'Apply 15 Days from Month End Rule', 'bool'),
                ]],
                ['heading', 'Bank Guarantee'],
                ['grid', 3, [
                    self::field('payment_terms.bank_guarantee_value', 'Value'), self::field('payment_terms.bank_guarantee_date', 'Date', 'date'), self::field('payment_terms.bank_guarantee_expiry', 'Expiry', 'date'),
                ]],
                ['heading', 'Security Cheque'],
                ['grid', 3, [
                    self::field('payment_terms.security_cheque_value', 'Value'), self::field('payment_terms.security_cheque_date', 'Date', 'date'), self::field('payment_terms.security_cheque_expiry', 'Expiry', 'date'),
                ]],
            ]],

            ['id' => 'lulu_receipt_terms', 'title' => 'Lulu Receipt Terms', 'blocks' => [['grid', 2, [
                self::field('trade_terms.receipt_terms', 'Terms', 'text', ['placeholder' => 'As per the agreed terms.']),
                self::field('trade_terms.receipt_mode', 'Mode', 'select', ['options' => $modes]),
            ]]]],

            ['id' => 'category', 'title' => 'Category', 'blocks' => [['note', 'Select "Yes" for every category this agreement applies to.'], ['categories']]],

            ['id' => 'expenses_liability', 'title' => 'Expenses Liability', 'blocks' => [['grid', 2, [
                $abc('trade_terms.transportation', 'Transportation'), $abc('trade_terms.loading_unloading', 'Loading / Unloading'),
                $abc('trade_terms.pilferage', 'Pilferage'), $abc('trade_terms.ullage', 'Ullage'),
                $abc('trade_terms.packing_forwarding', 'Packing / Forwarding'), $abc('trade_terms.insurance_in_transit', 'Insurance of Goods in Transit'),
                $abc('trade_terms.insurance_in_store', 'Insurance of Goods in Store'), $abc('trade_terms.vendor_returns_transportation', 'Vendor Returns Transportation'),
                self::field('trade_terms.returnable_goods', 'Returnable Goods', 'select', ['options' => ['Yes', 'No', 'NA']]),
                self::field('trade_terms.return_type', 'Return Type', 'select', ['options' => ['B to B Invoice', 'Delivery Challan (Debit Note)', 'Credit Note', 'NA']]),
            ]]]],

            ['id' => 'business_development_terms', 'title' => 'Business Development Terms', 'blocks' => [
                ['grid', 2, [
                    self::field('business_terms.period_from', 'Business Agreement Period From', 'date'),
                    self::field('business_terms.period_to', 'Business Agreement Period To', 'date'),
                    self::field('business_terms.vendor_listing_fee', 'Vendor Listing Fee', 'text', ['maxlength' => 1000]),
                    self::field('business_terms.brand_listing_fee', 'Brand Listing Fee', 'text', ['maxlength' => 1000]),
                    self::field('business_terms.sku_listing_fee', 'Article/SKU Listing Fee', 'text', ['maxlength' => 1000]),
                    self::field('business_terms.base_margin_regular', 'Base Margin - Regular Margin', 'text', ['maxlength' => 1000]),
                    self::field('business_terms.base_margin_eoss', 'Base Margin - EOSS Margin', 'text', ['maxlength' => 1000]),
                ]],
                ['table', ['Value / %', 'On Invoice', 'Off Invoice', 'Monthly', 'Quarterly', 'Yearly'], [
                    ['Fixed Rebate (% of Purchase)', ...self::numbers('rebate_lines.rebate:fixed_rebate', ['on_invoice', 'off_invoice', 'monthly', 'quarterly', 'yearly'])],
                    ['Primary Visibility', ...self::numbers('rebate_lines.rebate:primary_visibility', ['on_invoice', 'off_invoice', 'monthly', 'quarterly', 'yearly'])],
                    ['Secondary Visibility', ...self::numbers('rebate_lines.rebate:secondary_visibility', ['on_invoice', 'off_invoice', 'monthly', 'quarterly', 'yearly'])],
                    ['Logistics Rebate (% of Purchase)', ...self::numbers('rebate_lines.rebate:logistics_rebate', ['on_invoice', 'off_invoice', 'monthly', 'quarterly', 'yearly'])],
                    ['Unloading Charges', self::cell('business_terms.unloading_charges', 'text', 5, ['placeholder' => 'As per the regional norms', 'maxlength' => 1000])],
                ]],
                ['heading', 'Stock Correction'],
                ['table', ['% of Purchase', 'Half Yearly', 'Yearly'], [
                    self::numbers('rebate_lines.stock_correction:stock_correction', ['pct_of_purchase', 'half_yearly', 'yearly']),
                ]],
                ['heading', 'Central Payment / Early Payment / Data Sharing / Ullage'],
                ['table', ['Item', '% of Purchase', 'On Invoice', 'Off Invoice'], [
                    ['Central Payment', ...self::numbers('rebate_lines.other_terms:central_payment', ['pct_of_purchase', 'on_invoice', 'off_invoice'])],
                    ['Early Payment / CD', ...self::numbers('rebate_lines.other_terms:early_payment', ['pct_of_purchase', 'on_invoice', 'off_invoice'])],
                    ['Data Sharing', ...self::numbers('rebate_lines.other_terms:data_sharing', ['pct_of_purchase', 'on_invoice', 'off_invoice'])],
                    ['Ullage', ...self::numbers('rebate_lines.other_terms:ullage', ['pct_of_purchase', 'on_invoice', 'off_invoice'])],
                    ['Pilferage', self::cell('business_terms.pilferage_terms', 'text', 3, ['placeholder' => 'As per the agreed terms', 'maxlength' => 1000])],
                    ['Advertisement Support', self::cell('business_terms.advertisement_support', 'text', 3, ['maxlength' => 1000])],
                ]],
                ['heading', 'New Store Opening Support'],
                ['grid', 2, [
                    self::field('business_terms.new_store_support_daily', 'Lulu Daily', 'text', ['maxlength' => 1000]),
                    self::field('business_terms.new_store_support_hyper', 'Lulu Hyper', 'text', ['maxlength' => 1000]),
                    self::field('business_terms.deduction_mode', 'Deduction Mode', 'select', ['options' => $modes]),
                    self::field('business_terms.base_target', 'Base Target', 'select', ['options' => ['All Stores', 'Online', 'Corporate', 'New Store']]),
                ]],
            ]],

            ['id' => 'growth_rebate_slab', 'title' => 'Growth Rebate Slab', 'blocks' => [
                ['table', ['Slab', 'Target', 'Rebate'], array_map(fn ($n) => ["Slab $n", ...self::numbers("growth_slabs.$n", ['target', 'rebate'])], [1, 2, 3, 4, 5])],
                ['note', 'Any Brand/Product discontinued during the agreement period would result in the revision of the base target.'],
            ]],

            ['id' => 'margin_rebate', 'title' => 'Margin & Rebate', 'blocks' => [
                ['list_table', 'margins', 4, ['Material Category / Brand', 'Front End Margin', 'Back End Margin'], [
                    'material_category_brand' => ['text'], 'front_end_margin' => ['text'], 'back_end_margin' => ['text'],
                ]],
                ['table', ['', ''], [['Others', self::cell('business_terms.margin_others', 'text', 1, ['maxlength' => 1000])]]],
            ]],

            ['id' => 'merchandising_manpower_support', 'title' => 'Merchandising Manpower Support', 'blocks' => [['grid', 2, [
                self::field('business_terms.promoters_count', 'No of Promoters', 'number'),
                self::field('business_terms.promoters_sharing_value', 'Sharing Value (Lumpsum)', 'text', ['maxlength' => 1000]),
                self::field('business_terms.promoters_payroll', 'Pay Roll', 'select', ['options' => ['LuLu', 'Vendor/Brand']]),
                self::field('business_terms.promoters_uniform_cost', 'Uniform Cost', 'select', ['options' => ['LuLu', 'Vendor/Brand']]),
            ]]]],

            ['id' => 'payment_deduction', 'title' => 'Payment Deduction', 'blocks' => [['grid', 2, [
                self::field('business_terms.payment_deduction', 'Payment Deduction', 'select', ['options' => ['Debit', 'Cheque/RTGS']]),
            ]]]],

            ['id' => 'deliverables', 'title' => 'Deliverables / Secondary Visibility', 'blocks' => [['deliverables']]],

            ['id' => 'single_point_of_contact', 'title' => 'Single Point of Contact - Lulu', 'blocks' => [['table', ['Dept', '1st POC', 'Mobile', 'Email', '2nd POC', 'Mobile', 'Email'], array_map(
                fn ($d) => [$d, ...array_map(fn ($c) => self::cell("pocs.$d.$c", 'text'), ['first_poc', 'first_mobile', 'email', 'second_poc', 'second_mobile', 'second_email'])],
                ['Buying', 'Logistics', 'Finance', 'GST'],
            ), 'poc-table']]],

            ['title' => 'E-Sign', 'blocks' => [['esign']]],

            ['id' => 'signatories_bda', 'title' => 'Signatories - Business Development Agreement', 'blocks' => [
                ['heading', 'Lulu Signatory'],
                ['grid', 2, self::signatory('bda_lulu', 'Lulu')],
                ['heading', 'Vendor Signatory'],
                ['grid', 2, self::signatory('bda_vendor', 'Vendor')],
            ]],

            ['view' => 'tot._terms'],

            ['divider' => 'Annexures'],

            ['id' => 'annexure_a', 'title' => "Annexure A - Company's Privacy Notice", 'blocks' => [
                ['view', 'tot._annexure_a_text'],
                ['heading', 'Grievance Redressal'],
                ['grid', 2, [
                    self::field('business_terms.grievance_attention', 'Attention'),
                    self::field('business_terms.grievance_address', 'Address', 'text', ['maxlength' => 500]),
                    self::field('business_terms.grievance_email', 'Email Address', 'email'),
                ]],
                ['note', 'If the grievance is not redressed, the Vendor may make a complaint to the Data Protection Board of India established by the Central Government.'],
            ]],

            ['id' => 'annexure_b', 'title' => 'Annexure B - Board Resolution / Authorisation Letter of the Vendor', 'blocks' => [['documents', ['board_resolution']]]],
        ];
    }

    /** @return array<string,mixed> */
    public static function field(string $path, string $label, string $type = 'text', array $extra = []): array
    {
        return ['path' => $path, 'label' => $label, 'type' => $type] + $extra;
    }

    /** A table cell holding a field (no label). */
    public static function cell(string $path, string $type = 'text', int $colspan = 1, array $extra = []): array
    {
        return ['path' => $path, 'type' => $type, 'colspan' => $colspan, 'bare' => true] + $extra;
    }

    /** @param list<string> $fields */
    private static function numbers(string $base, array $fields): array
    {
        return array_map(fn ($f) => self::cell("$base.$f", 'number'), $fields);
    }

    private static function signatory(string $kind, string $who): array
    {
        return [
            self::field("signatories.$kind.name", "$who Signatory Name"),
            self::field("signatories.$kind.designation", "$who Signatory Designation"),
            self::field("signatories.$kind.contact_no", "$who Signatory Contact No", 'tel'),
            self::field("signatories.$kind.signed_date", "$who Signatory Date", 'date'),
            self::field("signatories.$kind.company_stamp", "$who Company Stamp"),
        ];
    }
}
