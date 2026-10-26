<?php

namespace Tests\Support;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Canned Admin API answers for the TOT form tests. */
class TotFixtures
{
    public const TOT_ID = 'TOTPUBLICID00001';

    public const TOKEN = 'ACCESS-TOKEN-0123456789-0123456789-0123456789-0123456789-abcd';

    /** A browser session that is signed in against the fake Admin. */
    public static function session(bool $mustChange = false): array
    {
        return [
            'admin_api.link' => ['token' => 'LINK-TOKEN-VALUE-0123', 'vendor_name' => 'Acme Foods Pvt Ltd', 'email_hint' => 's***@a***.com', 'financial_year' => '2026-27', 'type' => 'new', 'first_time_login' => false],
            'admin_api.token' => self::TOKEN,
            'admin_api.user' => ['id' => 'USERPUBLICID0001', 'name' => 'Acme', 'must_change_password' => $mustChange],
            'admin_api.tot' => ['id' => self::TOT_ID, 'vendor_name' => 'Acme Foods Pvt Ltd', 'financial_year' => '2026-27'],
            'admin_api.started_at' => time(),
            'admin_api.last_seen' => time(),
        ];
    }

    public static function masters(): array
    {
        return [
            'vendor_accounting_groups' => [['code' => 'MERCH', 'name' => 'Merchandise Vendor'], ['code' => 'SOR', 'name' => 'SOR Vendor']],
            'states' => [['code' => 'KL', 'name' => 'Kerala'], ['code' => 'KA', 'name' => 'Karnataka']],
            'regions' => [['code' => 'S', 'name' => 'South']],
            'company_codes' => [['code' => '9000', 'name' => 'Lulu Regional Office', 'city' => 'Kochi'], ['code' => '9001', 'name' => 'Lulu Trivandrum', 'city' => 'Trivandrum']],
            'purchase_orgs' => [['code' => '9000', 'description' => 'Lulu Kerala']],
            'categories' => [['code' => 'food', 'name' => 'Gro- Food'], ['code' => 'fv', 'name' => 'F&V'], ['code' => 'toys', 'name' => 'Toys']],
            'deliverable_assets' => [['code' => 'end_cap', 'name' => 'End Cap'], ['code' => 'pallet', 'name' => 'Pallett']],
        ];
    }

    /** The 14 originals as the Admin returns them in clause_templates (shortened wording, same keys / numbers / placeholders). */
    public static function clauseTemplates(): array
    {
        $titles = [
            'supply_invoicing' => 'Supply & Invoicing', 'taxes_gst' => 'Taxes/GST Obligations', 'compliance' => 'Compliance & Regulations',
            'intellectual_property' => 'Intellectual Property', 'damaged_defective' => 'Damaged/Defective Products and Return Policy',
            'other_obligations' => 'Other Obligations', 'termination' => 'Termination', 'anti_bribery' => 'Anti Bribery & Anti Corruption',
            'confidentiality' => 'Confidentiality', 'data_privacy' => 'Data Privacy', 'audit' => 'Audit', 'indemnity' => 'Indemnity',
            'amendments' => 'Amendments', 'governing_law' => 'Governing Law & Jurisdiction',
        ];
        $templates = [];
        $n = 0;
        foreach ($titles as $key => $title) {
            $n++;
            $text = "a) Original wording of clause $n, first sentence. Second sentence.

b) Original paragraph b of clause $n.";
            $placeholders = [];
            if ($key === 'damaged_defective') {
                $text = 'a) Payments shall be refunded within {refund_within_days} days from the date of intimation.'.'

'.'b) The Vendor shall be responsible for defects.';
                $placeholders = ['refund_within_days'];
            }
            if ($key === 'other_obligations') {
                $text = 'a) The shelf life shall not be less than {min_shelf_life_percent}%.'.'

'.'b) The Vendor shall provide correct HSN code.';
                $placeholders = ['min_shelf_life_percent'];
            }
            if ($key === 'data_privacy') {
                $text .= '

(i) As required by law.
(ii) Order processing.';
            }
            $templates[] = ['key' => $key, 'number' => $n, 'title' => $title, 'text' => $text, 'placeholders' => $placeholders];
        }

        return $templates;
    }

    public static function detail(array $over = []): array
    {
        return array_replace_recursive([
            'tot' => ['id' => self::TOT_ID, 'tot_number' => 12, 'financial_year' => '2026-27', 'type' => 'new', 'vendor_name' => 'Acme Foods Pvt Ltd',
                'status' => 'pending_vendor', 'status_label' => 'Pending with vendor', 'editable' => true, 'submitted_at' => null],
            'form' => [
                'vendor_details' => ['vendor_name' => 'Acme Foods Pvt Ltd', 'pan_no' => 'ABCDE1234F', 'gst_type' => 'GST Registered', 'state' => 'KL', 'purchase_org' => '9000', 'vendor_accounting_group' => 'MERCH'],
                'company_codes' => ['9000', '9001'],
                'contacts' => [['type' => 'sales', 'name' => 'Sam', 'phone' => '+919999999999', 'email' => 'sam@acme.test'], ['type' => 'finance', 'name' => 'Fin', 'phone' => null, 'email' => null]],
                'bank' => ['account_name' => 'Acme', 'account_no' => '123456789012', 'ifsc' => 'HDFC0001234', 'verification_status' => 'pending', 'validation_failed_override' => false],
                'outlets' => [['state' => 'KL', 'is_all' => false, 'store_1' => '101']],
                'payment_terms' => ['days_from_grn' => 30, 'payment_mode' => 'ePayment', 'apply_15_day_rule' => true, 'reconciliation_type' => 'Tenant Payable'],
                'trade_terms' => [], 'categories' => ['food'], 'business_terms' => ['period_from' => '2026-04-01'],
                'rebate_lines' => [['group' => 'rebate', 'kind' => 'fixed_rebate', 'on_invoice' => 2.5]],
                'growth_slabs' => [['slab_no' => 1, 'target' => 100, 'rebate' => 1]],
                'margins' => [['material_category_brand' => 'Biscuits', 'front_end_margin' => '10', 'back_end_margin' => '2']],
                'deliverables' => [['asset' => 'end_cap', 'numbers' => '3']],
                'pocs' => [['dept' => 'Buying', 'first_poc' => 'Bea']], 'signatories' => [],
            ],
            'clause_templates' => self::clauseTemplates(),
            'flagged_sections' => [],
            'locked_fields' => ['company_codes', 'vendor_details.purchase_org', 'vendor_details.vendor_accounting_group', 'payment_terms.reconciliation_type'],
            'required_fields' => ['vendor_details.vendor_name', 'vendor_details.gst_regn_no', 'contacts.sales.phone', 'bank.account_no', 'payment_terms.terms', 'payment_terms.payment_mode'],
            'documents' => [['id' => 77, 'type' => 'pan_certificate', 'name' => 'pan.pdf', 'size' => 2048, 'uploaded_at' => '2026-10-20']],
            'required_documents' => ['pan_certificate', 'cancelled_cheque', 'rtgs_form', 'gst_certificate (when GST Registered)', 'msme_certificate (when MSME Registered)'],
            'reviewer_comments' => [],
            'draft_saved_at' => '2026-10-20T05:12:00+00:00',
            'progress' => ['current' => 'vendor', 'steps' => [
                ['key' => 'initiated', 'label' => 'TOT initiated', 'state' => 'done', 'entered_at' => '2026-10-20T09:00:00+00:00'],
                ['key' => 'vendor', 'label' => 'Vendor details & submission', 'state' => 'current', 'entered_at' => null],
                ['key' => 'buyer', 'label' => 'Buyer review', 'state' => 'pending', 'entered_at' => null],
            ]],
        ], $over);
    }

    /**
     * Route fake Admin answers by "METHOD /path" (path relative to /api/v1). Callables receive the Request.
     *
     * @param  array<string,mixed>  $routes
     */
    public static function fake(array $routes = [], ?array $detail = null): void
    {
        Http::swap(new Factory); // a fresh fake each call: the first registered stub would otherwise win

        $defaults = [
            'GET masters' => Http::response(self::masters()),
            'GET tots/'.self::TOT_ID => Http::response($detail ?? self::detail()),
            'PUT tots/'.self::TOT_ID.'/draft' => Http::response(['saved' => ['vendor_details'], 'ignored' => []]),
            'POST tots/'.self::TOT_ID.'/documents' => Http::response(['document' => ['id' => 1, 'type' => 'x']], 201),
        ];

        Http::fake(function (Request $request) use ($routes, $defaults) {
            $key = $request->method().' '.ltrim(substr(parse_url($request->url(), PHP_URL_PATH), strlen('/api/v1')), '/');
            $answer = $routes[$key] ?? $defaults[$key] ?? Http::response(['error' => 'not faked: '.$key], 404);

            return is_callable($answer) ? $answer($request) : $answer;
        });
    }

    /** The JSON body of the (last) request matching "METHOD path", for assertions. */
    public static function sent(string $method, string $path): ?array
    {
        $match = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => $r->method() === $method && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/'.$path))->last();

        return $match ? $match->data() : null;
    }
}
