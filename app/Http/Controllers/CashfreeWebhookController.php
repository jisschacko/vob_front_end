<?php

namespace App\Http\Controllers;

use App\Models\CashfreeVerification;
use App\Services\AdminApi\ApiLogger;
use App\Services\Cashfree\BankVerificationService;
use App\Services\Cashfree\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * POST /webhooks/cashfree. Public, no session. The payload only says "something changed for verification X": the new status is read
 * back from Cashfree, so a forged or replayed webhook cannot change a result. The Admin is told when the vendor's page next polls or opens
 * (the Admin call needs the vendor's session token).
 */
class CashfreeWebhookController extends Controller
{
    public function __invoke(Request $request, BankVerificationService $service, ApiLogger $logger): Response
    {
        $payload = $request->json()->all();
        $valid = WebhookSignature::valid($payload, config('cashfree.client_secret'));

        $logger->record('cashfree', 'POST', $request->url(), $request->headers->all(), $request->getContent(), $valid ? 200 : 404, null, 0, null,
            $valid ? null : 'Invalid webhook signature', 'in');

        if (! $valid) {
            abort(404);
        }

        $row = CashfreeVerification::where('verification_id', (string) data_get($payload, 'data.verification_id'))->first();
        if ($row) {
            try {
                $service->refresh($row);
            } catch (Throwable $e) {
                report($e); // Cashfree retries; the vendor's page also polls
            }
        }

        return response('ok');
    }
}
