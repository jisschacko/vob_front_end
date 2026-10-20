<?php

namespace Tests\Support;

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
