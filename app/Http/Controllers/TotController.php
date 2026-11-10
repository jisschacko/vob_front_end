<?php

namespace App\Http\Controllers;

use App\Services\AdminApi\AdminApiClient;
use App\Services\AdminApi\AdminSession;
use App\Services\Cashfree\BankVerificationService;
use App\Services\Cashfree\FieldVerifier;
use App\Services\Tot\Masters;
use App\Services\Tot\SubmitReadiness;
use App\Services\Tot\TotFormMapper;
use App\Services\Tot\TotPage;
use App\Support\DemoLenientFields;
use App\Support\EnforcementSwitches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\MessageBag;

/**
 * The vendor's Terms of Trade form. The TOT id never appears in a URL: it lives in the server-side session (from login).
 */
class TotController extends Controller
{
    public function __construct(
        private readonly AdminApiClient $api,
        private readonly AdminSession $session,
        private readonly Masters $masters,
        private readonly TotFormMapper $mapper,
        private readonly SubmitReadiness $readiness,
    ) {}

    public function show(Request $request)
    {
        $tot = $this->session->tot();
        if ($tot === null) { // signed in without an invitation link: choose among the vendor's TOTs
            $count = count($this->session->tots());
            if ($count === 1) {
                $this->session->selectTot(0);

                return redirect()->route('home');
            }

            return $count > 1 ? redirect()->route('tots.index') : view('home', ['tot' => null]);
        }

        $response = $this->api->get('tots/'.$tot['id']);
        if (! $response->successful()) {
            return response()->view('errors.service-unavailable', [], 503);
        }

        $this->masters->all(); // load now: a failure must become the neutral 503 page, not a view error

        $detail = $response->json();
        $request->session()->put('tot.locked', array_values((array) ($detail['locked_fields'] ?? [])));
        $page = new TotPage($detail, $this->masters, $this->mapper, $request->session()->get('errors')?->getBag('default') ?? new MessageBag);

        return view('tot.show', [
            'page' => $page,
            'tot' => $page->tot(),
            'progress' => $detail['progress'] ?? ['steps' => []],
            'vendorName' => $page->tot()['vendor_name'] ?? ($tot['vendor_name'] ?? null),
            'subtitle' => isset($page->tot()['financial_year']) ? 'Terms of Trade '.$page->tot()['financial_year'] : null,
            'draftSavedAt' => $detail['draft_saved_at'] ?? null,
            'currentNote' => $page->reviewerComments() !== [] && $page->editable() ? 'Action needed: see reviewer comments' : null,
        ]);
    }

    /** Save Draft button: POST, then back to the form. */
    public function save(Request $request): RedirectResponse
    {
        $result = $this->saveDraft($request);

        if ($result['ok']) {
            return redirect()->route('home')->with('status', 'Draft saved.');
        }

        return back()->withInput()->withErrors($result['errors'] ?: ['form' => $result['message']]);
    }

    /**
     * Submit (or resubmit after the reviewers sent it back). The posted form is saved first, then the mandatory-field / document checks
     * the development switches allow, then POST /tots/{id}/submit. After a successful submit the Admin makes the TOT read-only.
     */
    public function submit(Request $request): RedirectResponse
    {
        $tot = $this->session->tot();
        if ($tot === null) {
            return redirect()->route('home');
        }

        $saved = $this->saveDraft($request);
        if (! $saved['ok']) {
            return back()->withInput()->withErrors($saved['errors'] ?: ['form' => $saved['message']]);
        }

        $detail = $this->api->get('tots/'.$tot['id']);
        if (! $detail->successful()) {
            return back()->withInput()->withErrors(['form' => 'We could not submit right now. Please try again.']);
        }

        $missing = $this->readiness->missing($detail->json(), EnforcementSwitches::required(), EnforcementSwitches::documents()) + $this->failedChecks($detail->json());
        if ($missing !== []) {
            return back()->withInput()->withErrors(['submit' => 'Please complete the following before submitting.'] + $missing);
        }

        $response = $this->api->post('tots/'.$tot['id'].'/submit');

        if ($response->successful()) {
            $warnings = $this->readiness->fromAdmin((array) $response->json('warnings', []));

            return redirect()->route('home')->with('status', 'Your Terms of Trade has been submitted for review.')
                ->with('submit_warnings', array_values($warnings));
        }

        return back()->withInput()->withErrors(match ($response->status()) {
            422 => ['submit' => 'Please complete the following before submitting.'] + $this->readiness->fromAdmin((array) $response->json('errors', [])),
            409 => ['form' => 'This Terms of Trade can no longer be edited.'],
            default => ['form' => 'We could not submit right now. Please try again.'],
        });
    }

    /**
     * Values the background Cashfree check (FieldVerificationController) already reported as NOT valid for what is saved now.
     * "Could not check" never blocks; the bank account is gated by the Admin through its verification status.
     *
     * @param  array<string,mixed>  $detail  GET /tots/{id}
     * @return array<string,string> field path => message
     */
    private function failedChecks(array $detail): array
    {
        if (EnforcementSwitches::skipThirdParty() || ! EnforcementSwitches::required()) {
            return [];
        }

        $form = (array) ($detail['form'] ?? []);
        $checks = [
            'vendor_details.pan_no' => ['pan', 'The PAN could not be verified. Check the PAN number.'],
            'bank.ifsc' => ['ifsc', 'The IFS Code was not found. Check the IFS Code.'],
        ];
        if (data_get($form, 'vendor_details.gst_type') === 'GST Registered') {
            $checks['vendor_details.gst_regn_no'] = ['gstin', 'The GSTIN could not be verified. Check the GST Regn. No.'];
        }

        $problems = [];
        foreach ($checks as $path => [$type, $message]) {
            $value = FieldVerifier::clean((string) data_get($form, $path));
            if ($value === '' || str_contains($value, '*')) {
                continue;
            }
            if (($this->session->cached($type.':'.hash('sha256', $value))['state'] ?? null) === 'invalid') {
                $problems[$path] = $message;
            }
        }

        return $problems;
    }

    /** Autosave (same call, JSON answer). */
    public function autosave(Request $request): JsonResponse
    {
        $result = $this->saveDraft($request);

        if ($result['ok']) {
            // the Admin stamps draft_saved_at; fall back to our clock if it is not in the answer
            $at = $result['saved_at'] ? Carbon::parse($result['saved_at']) : now();

            return response()->json(['saved' => true, 'saved_at' => $at->setTimezone(config('vob.display_timezone'))->format('d-M-Y, h:i A')]);
        }

        return response()->json(['saved' => false, 'message' => $result['message'], 'errors' => $result['errors']], $result['status']);
    }

    /**
     * @return array{ok:bool,status:int,message:string,errors:array<string,string>}
     */
    private function saveDraft(Request $request): array
    {
        $tot = $this->session->tot();
        if ($tot === null) {
            return ['ok' => false, 'status' => 404, 'message' => 'No Terms of Trade is linked to this sign-in.', 'errors' => []];
        }

        $input = $request->input('form');
        $payload = $this->mapper->payload(
            is_array($input) ? $input : [],
            (array) $request->session()->get('tot.locked', []), // as the Admin reported them when the page was shown
            array_values(array_filter((array) $request->input('masked', []), 'is_string')),
            array_keys($this->masters->options('deliverable_assets')),
        );

        $errors = $this->bankFormatErrors(is_array($input) ? $input : [], $payload);
        if ($errors !== []) {
            return ['ok' => false, 'status' => 422, 'message' => 'Some values need correcting. Nothing was saved.', 'errors' => $errors];
        }

        if ($payload['form'] === []) {
            return ['ok' => true, 'status' => 200, 'message' => '', 'errors' => []];
        }

        $response = $this->api->put('tots/'.$tot['id'].'/draft', ['form' => $payload['form']]);

        if ($response->successful()) {
            return ['ok' => true, 'status' => 200, 'message' => '', 'errors' => [], 'saved_at' => $response->json('draft_saved_at')];
        }

        return match ($response->status()) {
            422 => ['ok' => false, 'status' => 422, 'message' => 'Some values need correcting. Nothing was saved.',
                'errors' => $this->mapper->errorsByPath((array) $response->json('errors', []), $payload['keyed'])],
            409 => ['ok' => false, 'status' => 409, 'message' => 'This Terms of Trade can no longer be edited.', 'errors' => []],
            default => ['ok' => false, 'status' => 502, 'message' => 'We could not save right now. Please try again.', 'errors' => []],
        };
    }

    /**
     * IFS Code format (11 characters: 4 capital letters, a 0, 6 letters/digits; typed letters are capitalised) and the account number
     * typed twice. The ifsc in $payload is replaced by its capitalised form.
     *
     * @param  array<string,mixed>  $input
     * @param  array{form:array<string,mixed>}  $payload
     * @return array<string,string>
     */
    private function bankFormatErrors(array $input, array &$payload): array
    {
        $errors = [];

        if (! DemoLenientFields::is('bank.ifsc') && isset($payload['form']['bank']['ifsc']) && is_string($payload['form']['bank']['ifsc'])) {
            $ifsc = strtoupper(preg_replace('/\s+/', '', $payload['form']['bank']['ifsc']));
            $payload['form']['bank']['ifsc'] = $ifsc;
            if ($ifsc !== '' && ! preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
                $errors['bank.ifsc'] = 'IFS Code must be 11 characters: 4 capital letters, then 0, then 6 letters or digits.';
            }
        }

        $account = $payload['form']['bank']['account_no'] ?? null;
        $confirm = $input['bank']['account_no_confirm'] ?? null;
        if (is_string($account) && is_string($confirm) && $confirm !== '' && $confirm !== $account) {
            $errors['bank.account_no_confirm'] = 'The account numbers do not match.';
        }

        return $errors;
    }
}
