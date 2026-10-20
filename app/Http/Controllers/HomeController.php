<?php

namespace App\Http\Controllers;

use App\Services\AdminApi\AdminApiClient;
use App\Services\AdminApi\AdminSession;

/** Landing page after sign-in: the status tracker and TOT summary. The form itself arrives in Phase 3. */
class HomeController extends Controller
{
    public function __invoke(AdminApiClient $api, AdminSession $session)
    {
        $tot = $session->tot();
        if ($tot === null) {
            return view('home', ['tot' => null]);
        }

        $response = $api->get('tots/'.$tot['id']);
        if (! $response->successful()) {
            return response()->view('errors.service-unavailable', [], 503);
        }

        $detail = $response->json();

        return view('home', [
            'tot' => $detail['tot'] ?? $tot,
            'progress' => $detail['progress'] ?? ['steps' => []],
            'vendorName' => $detail['tot']['vendor_name'] ?? ($tot['vendor_name'] ?? null),
            'subtitle' => isset($detail['tot']['financial_year']) ? 'Terms of Trade '.$detail['tot']['financial_year'] : null,
            'draftSavedAt' => $detail['draft_saved_at'] ?? null,
            'reviewerComments' => $detail['reviewer_comments'] ?? [],
        ]);
    }
}
