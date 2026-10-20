<?php

namespace App\Http\Controllers;

use App\Services\AdminApi\AdminApiClient;
use App\Services\AdminApi\AdminSession;

class OnboardController extends Controller
{
    /** The emailed link: /onboard/{token}. The token is checked by the Admin, stored server-side, and dropped from the URL. */
    public function show(string $token, AdminApiClient $api, AdminSession $session)
    {
        $response = $api->resolveLink($token);

        if ($response->successful() && $response->json('valid') === true) {
            $session->rememberLink($token, $response->json());

            return redirect()->route('sign-in');
        }

        if ($response->status() === 422) { // tampered, expired or replaced by a newer invitation
            $session->forgetLink();

            return response()->view('link-invalid', [], 410);
        }

        // 404 = request rejected by the Admin gate, anything else = unexpected: never explain why.
        return response()->view('errors.service-unavailable', [], 503);
    }

    public function invalid()
    {
        return response()->view('link-invalid', [], 410);
    }
}
