<?php

namespace App\Http\Controllers;

use App\Models\CashfreeVerification;
use App\Services\AdminApi\AdminApiClient;
use App\Services\AdminApi\AdminSession;
use App\Services\Cashfree\BankVerificationService;
use App\Services\Cashfree\CashfreeException;
use App\Support\EnforcementSwitches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

/** "Verify bank account": pay Rs 1 by UPI from the entered account. Ids stay in the server-side session, never in a URL. */
class BankVerificationController extends Controller
{
    private const PENDING = 'cashfree.pending';

    private const MESSAGES = [
        'verified' => 'Bank account verified.',
        'failed' => 'Bank details validation failed. Check the account number and IFS code and pay from the same account. If it keeps failing, tick "Bank details validation failed" on the form to continue with manual approval.',
        'expired' => 'The payment link expired. Please start the verification again.',
    ];

    public function __construct(
        private readonly AdminApiClient $api,
        private readonly AdminSession $session,
        private readonly BankVerificationService $service,
    ) {}

    public function create(): RedirectResponse
    {
        $tot = $this->session->tot();
        if ($tot === null) {
            return redirect()->route('home');
        }

        $detail = $this->api->get('tots/'.$tot['id']);
        if (! $detail->successful()) {
            return back()->withErrors(['bank_verify' => 'We could not load your bank details. Please try again.']);
        }
        if (! $detail->json('tot.editable')) {
            return back()->withErrors(['bank_verify' => 'This Terms of Trade can no longer be edited.']);
        }

        if (EnforcementSwitches::skipThirdParty()) { // demo: bank fields are not validated, the account is simply shown as verified
            return $this->simulate($tot['id']);
        }

        $bank = (array) $detail->json('form.bank', []);
        foreach (['account_name', 'account_no', 'ifsc'] as $field) {
            if (blank($bank[$field] ?? null)) {
                return back()->withErrors(['bank_verify' => 'Enter the account name, account number and IFS code, then verify.']);
            }
        }
        if (str_contains((string) $bank['account_no'], '*')) {
            return back()->withErrors(['bank_verify' => 'Please re-enter the full account number, then verify.']);
        }

        try {
            $row = $this->service->start($tot['id'], $this->session->userPublicId(), $bank);
        } catch (CashfreeException $e) {
            return back()->withErrors(['bank_verify' => $e->getMessage()]);
        }

        session()->put(self::PENDING, $row->verification_id);

        return redirect()->route('bank.verify');
    }

    /** VOB_SKIP_THIRD_PARTY: no Cashfree call. The Admin is told the account is verified, with a DEMO reference. */
    private function simulate(string $totId): RedirectResponse
    {
        $response = $this->api->post('tots/'.$totId.'/bank-verification', ['status' => 'verified', 'reference' => 'DEMO-'.strtoupper(Str::random(10))]);

        if ($response->successful()) {
            return redirect()->route('home')->with('status', 'Demo mode: bank verification was skipped and the account is marked as verified.');
        }

        return back()->withErrors(['bank_verify' => 'We could not record the verification right now. Please try again.']);
    }

    public function show()
    {
        $row = $this->pending();
        if ($row === null || $row->isFinal() && $this->service->outcome($row) !== 'pending') {
            session()->forget(self::PENDING);

            return redirect()->route('home');
        }

        return view('bank-verify', ['row' => $row, 'links' => (array) $row->payload]);
    }

    /** Polled by the verify page (and the "Check now" button). */
    public function status(): JsonResponse
    {
        $row = $this->pending();
        if ($row === null) {
            return response()->json(['state' => 'none', 'done' => true, 'message' => self::MESSAGES['expired']]);
        }

        try {
            $state = $this->service->settle($this->service->refresh($row));
        } catch (CashfreeException $e) {
            return response()->json(['state' => 'pending', 'done' => false, 'message' => $e->getMessage()]);
        }

        if ($state === 'pending') {
            return response()->json(['state' => 'pending', 'done' => false, 'message' => 'Waiting for the payment...']);
        }

        session()->forget(self::PENDING);
        session()->flash('status', self::MESSAGES[$state]);

        return response()->json(['state' => $state, 'done' => true, 'message' => self::MESSAGES[$state]]);
    }

    private function pending(): ?CashfreeVerification
    {
        $tot = $this->session->tot();
        $id = session(self::PENDING);
        if ($tot === null || ! is_string($id)) {
            return null;
        }

        return CashfreeVerification::where('verification_id', $id)->where('tot_ref', $tot['id'])->first();
    }
}
