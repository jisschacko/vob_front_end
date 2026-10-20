<?php

namespace App\Services\Cashfree;

use App\Models\CashfreeVerification;
use App\Services\AdminApi\AdminApiClient;
use App\Services\AdminApi\AdminApiException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Bank account verification by Reverse Penny Drop: the vendor pays Rs 1 by UPI from the account they entered; Cashfree reports the
 * paying account, and it must match the account number and IFSC on the form. The result is then reported to the Admin
 * (POST /tots/{id}/bank-verification). The account number itself is never stored here, only its last four digits and a keyed hash.
 */
class BankVerificationService
{
    public function __construct(
        private readonly CashfreeClient $cashfree,
        private readonly AdminApiClient $admin,
    ) {}

    /**
     * Create a request for the bank details currently on the form.
     *
     * @param  array{account_name?:?string,account_no?:?string,ifsc?:?string}  $bank
     *
     * @throws CashfreeException
     */
    public function start(string $totId, ?string $userId, array $bank): CashfreeVerification
    {
        $account = $this->clean($bank['account_no'] ?? '');
        $ifsc = $this->clean($bank['ifsc'] ?? '');
        $verificationId = 'vob'.bin2hex(random_bytes(12)); // <= 50 chars, [A-Za-z0-9]

        $created = $this->cashfree->create($verificationId, $bank['account_name'] ?? null, route('home'));

        $expires = isset($created['valid_upto']) ? Carbon::parse($created['valid_upto']) : now()->addMinutes((int) config('cashfree.link_minutes'));

        return CashfreeVerification::create([
            'verification_id' => $verificationId,
            'ref_id' => isset($created['ref_id']) ? (string) $created['ref_id'] : null,
            'tot_ref' => $totId,
            'user_public_id' => $userId,
            'status' => 'CREATED',
            'account_last4' => substr($account, -4),
            'expected_fingerprint' => $this->fingerprint($account, $ifsc),
            'payload' => array_intersect_key($created, array_flip(['upi_link', 'gpay', 'bhim', 'paytm', 'phonepe', 'qr_code'])),
            'expires_at' => $expires->isPast() ? now()->addMinutes((int) config('cashfree.link_minutes')) : $expires,
        ]);
    }

    /** Ask Cashfree for the current status (the authoritative answer; webhook payloads are never trusted) and update the row. */
    public function refresh(CashfreeVerification $row): CashfreeVerification
    {
        if ($row->isFinal()) {
            return $row;
        }

        $data = $this->cashfree->status($row->verification_id);
        $status = strtoupper((string) ($data['status'] ?? ''));

        if (! empty($data['ref_id'])) {
            $row->ref_id = (string) $data['ref_id'];
        }

        if ($status === 'SUCCESS') {
            $row->name_match_result = isset($data['name_match_result']) && $data['name_match_result'] !== '' ? (string) $data['name_match_result'] : null;
            $row->status = $this->matches($row, $data) ? 'SUCCESS' : 'MISMATCH';
        } elseif (in_array($status, ['FAILURE', 'EXPIRED'], true)) {
            $row->status = $status;
        }

        $row->save();

        return $row;
    }

    /**
     * Report a final result to the Admin once. Returns the vendor-facing state: pending | verified | failed | expired.
     *
     * @throws AdminApiException
     */
    public function settle(CashfreeVerification $row): string
    {
        $outcome = $this->outcome($row);

        if ($outcome === 'expired') {
            return 'expired';
        }
        if ($outcome === 'pending' || $row->reported_status !== null) {
            return $outcome;
        }

        $payload = $outcome === 'verified'
            ? ['status' => 'verified', 'reference' => (string) ($row->ref_id ?: $row->verification_id)]
            : ['status' => 'failed'];

        $response = $this->admin->post('tots/'.$row->tot_ref.'/bank-verification', $payload);

        if ($response->successful()) {
            $row->update(['reported_status' => $outcome === 'verified' ? 'verified' : 'failed', 'reported_at' => now()]);
        } elseif ($response->status() === 409) { // the TOT is no longer editable: nothing to record
            $row->update(['reported_status' => 'skipped', 'reported_at' => now()]);
        } else {
            return 'pending'; // not recorded yet: the next status check retries
        }

        return $outcome;
    }

    /** pending | verified | failed | expired (from the row only). */
    public function outcome(CashfreeVerification $row): string
    {
        return match ($row->status) {
            'SUCCESS' => 'verified',
            'FAILURE', 'MISMATCH' => 'failed',
            'EXPIRED' => 'expired',
            default => 'pending',
        };
    }

    /**
     * Pick up a result the vendor never saw (browser closed after paying) and report it to the Admin.
     * Called when the form opens; never throws.
     */
    public function reconcile(string $totId): void
    {
        try {
            $row = CashfreeVerification::where('tot_ref', $totId)->whereNull('reported_status')->where('status', '!=', 'EXPIRED')
                ->where('created_at', '>', now()->subHour())->latest('id')->first();
            if ($row) {
                $this->settle($this->refresh($row));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Keyed hash of "account|ifsc": lets us compare without keeping the account number. */
    public function fingerprint(string $account, string $ifsc): string
    {
        return hash_hmac('sha256', $this->clean($account).'|'.$this->clean($ifsc), (string) config('app.key'));
    }

    private function matches(CashfreeVerification $row, array $data): bool
    {
        $accountMatches = hash_equals($row->expected_fingerprint, $this->fingerprint((string) ($data['bank_account'] ?? ''), (string) ($data['ifsc'] ?? '')));

        $nameOk = config('cashfree.name_match') !== 'reject_no_match'
            || strtoupper((string) ($data['name_match_result'] ?? '')) !== 'NO_MATCH';

        return $accountMatches && $nameOk;
    }

    private function clean(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', $value) ?? '');
    }
}
