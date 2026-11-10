<?php

namespace App\Http\Controllers;

use App\Services\AdminApi\AdminApiClient;
use App\Services\AdminApi\AdminSession;
use App\Services\Cashfree\FieldVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Background checks while the vendor fills the form: PAN, GSTIN, IFSC and bank account through Cashfree. Signed-in vendors only, POST + CSRF,
 * throttled (each call can cost money, and the bank check reveals the holder's name). Answers are kept in the server-side session per
 * value, so the same value is never paid for twice. Nothing here is written to a URL or an api_logs row unmasked.
 */
class FieldVerificationController extends Controller
{
    public function __construct(
        private readonly FieldVerifier $verifier,
        private readonly AdminApiClient $api,
        private readonly AdminSession $session,
    ) {}

    public function pan(Request $request): JsonResponse
    {
        return $this->simple($request, 'pan', 'pan');
    }

    public function gstin(Request $request): JsonResponse
    {
        return $this->simple($request, 'gstin', 'gstin');
    }

    public function ifsc(Request $request): JsonResponse
    {
        return $this->simple($request, 'ifsc', 'ifsc');
    }

    /** @param  'pan'|'gstin'|'ifsc'  $type */
    private function simple(Request $request, string $type, string $field): JsonResponse
    {
        if ($this->session->tot() === null) {
            return response()->json(['state' => 'unavailable', 'message' => 'No Terms of Trade is linked to this sign-in.', 'detail' => []], 404);
        }

        $value = FieldVerifier::clean((string) $request->validate([$field => ['required', 'string', 'max:40']])[$field]);

        return response()->json($this->remembered($type, $value, fn () => $this->verifier->{$type}($value)));
    }

    /**
     * Bank account: the page saves the draft first, then asks here. The saved account number and IFSC are what Cashfree checks (a value
     * only typed in the browser can never be marked verified), and the result is recorded with the Admin, which the Admin's submit check needs.
     */
    public function bank(Request $request): JsonResponse
    {
        // The request carries nothing to edit: what is checked is always read from the saved draft. A request with any payload, query string
        // or JSON body has been tampered with (or is not ours) and is refused before anything is read, recorded or sent to Cashfree.
        $multipart = str_starts_with((string) $request->header('Content-Type'), 'multipart/form-data');
        if ($request->except('_token') !== [] || $request->query() !== [] || $request->allFiles() !== []
            || (! $multipart && ! in_array(trim($request->getContent()), ['', '[]', '{}'], true))) {
            return $this->bankAnswer('unavailable', 'This request was not accepted.', 400);
        }

        $tot = $this->session->tot();
        if ($tot === null) {
            return $this->bankAnswer('unavailable', 'No Terms of Trade is linked to this sign-in.', 404);
        }

        $detail = $this->api->get('tots/'.$tot['id']);
        if (! $detail->successful()) {
            return $this->bankAnswer('unavailable', 'We could not load your bank details. Please try again.', 502);
        }
        if (! $detail->json('tot.editable')) {
            return $this->bankAnswer('unavailable', 'This Terms of Trade can no longer be edited.', 409);
        }

        $bank = (array) $detail->json('form.bank', []);
        $account = FieldVerifier::clean((string) ($bank['account_no'] ?? ''));
        $ifsc = FieldVerifier::clean((string) ($bank['ifsc'] ?? ''));
        $name = trim((string) ($bank['account_name'] ?? ''));

        if ($account === '' || $ifsc === '' || $name === '') {
            return $this->bankAnswer('incomplete', 'Enter the account name, account number and IFS Code to verify the account.');
        }
        if (str_contains($account, '*')) {
            return $this->bankAnswer('incomplete', 'Please re-enter the full account number to verify the account.');
        }

        $key = 'bank:'.hash_hmac('sha256', $account.'|'.$ifsc.'|'.mb_strtolower($name), (string) config('app.key'));
        $known = $this->session->cached($key);
        $recorded = $detail->json('form.bank.verification_status');

        if ($known !== null && $known['state'] === 'valid' && $recorded === 'verified') {
            return response()->json($known); // already checked and already known to the Admin: nothing to pay for or send
        }

        $result = $known ?? $this->verifier->bank($account, $ifsc, $name);
        if ($result['state'] === 'unavailable') {
            return response()->json($result);
        }

        // tell the Admin (its submit needs "verified"); a result that cannot be recorded is not cached, so the next try repeats the recording
        $body = $result['state'] === 'valid'
            ? ['status' => 'verified', 'reference' => (string) ($result['detail']['reference'] ?? 'CF-'.substr(sha1($key), 0, 10))]
            : ['status' => 'failed'];
        $recordedNow = $this->api->post('tots/'.$tot['id'].'/bank-verification', $body);
        if (! $recordedNow->successful()) {
            return $this->bankAnswer('unavailable', 'We could not record the verification right now. Please try again.', 502);
        }

        $this->session->remember($key, $result);

        return response()->json($result);
    }

    /** Cached answer for this exact value, else ask Cashfree (an "unavailable" answer is never cached, so it is retried). */
    private function remembered(string $type, string $value, callable $ask): array
    {
        $key = $type.':'.hash('sha256', $value);
        $known = $this->session->cached($key);
        if ($known !== null) {
            return $known;
        }

        $result = $ask();
        if ($result['state'] !== 'unavailable') {
            $this->session->remember($key, $result);
        }

        return $result;
    }

    private function bankAnswer(string $state, string $message, int $http = 200): JsonResponse
    {
        return response()->json(['state' => $state, 'message' => $message, 'detail' => []], $http);
    }
}
