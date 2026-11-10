<?php

namespace App\Services\Cashfree;

use App\Support\EnforcementSwitches;
use Illuminate\Support\Str;

/**
 * Background checks of PAN, GSTIN, IFSC and bank account through Cashfree, one answer shape for the page:
 *   state   valid | invalid | unavailable (Cashfree could not tell: our fault, theirs, credits, IP whitelist...)
 *   message short text for the vendor (never Cashfree's own wording or codes; those stay in api_logs)
 *   detail  what to show / fill in (registered name, bank, branch...)
 * A format that cannot be right is refused here without a call, so nothing is paid for it.
 * VOB_SKIP_THIRD_PARTY: nothing is called and everything is reported valid (demo).
 */
class FieldVerifier
{
    public const PAN = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';

    public const GSTIN = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    public const IFSC = '/^[A-Z]{4}0[A-Z0-9]{6}$/';

    /** account_status_code values that mean the account itself is not usable (the vendor must correct it) */
    private const BAD_ACCOUNT = ['INVALID_ACCOUNT_FAIL', 'ACCOUNT_BLOCKED', 'INVALID_IFSC_FAIL', 'NRE_ACCOUNT_FAIL', 'FRAUD_ACCOUNT'];

    public function __construct(private readonly CashfreeClient $cashfree) {}

    public static function clean(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', $value));
    }

    public function pan(string $pan): array
    {
        if (! preg_match(self::PAN, $pan)) {
            return $this->invalid('Enter a valid PAN, for example ABCDE1234F.');
        }
        if (EnforcementSwitches::skipThirdParty()) {
            return $this->valid('PAN verified.');
        }

        $r = $this->call(fn () => $this->cashfree->pan($pan));
        if ($r === null) {
            return $this->unavailable();
        }
        ['status' => $status, 'json' => $j] = $r;

        if ($status === 200) {
            $pStatus = strtoupper((string) ($j['pan_status'] ?? ''));
            if (($j['valid'] ?? false) !== true) {
                return $this->invalid('This PAN is not valid.');
            }
            if (in_array($pStatus, ['DELETED', 'DEACTIVATED', 'INVALID'], true)) {
                return $this->invalid('This PAN is not active.');
            }

            return $this->valid('PAN verified.', array_filter(['name' => $j['registered_name'] ?? null, 'type' => $j['type'] ?? null]));
        }

        return $status === 400 && str_starts_with((string) ($j['code'] ?? ''), 'pan') ? $this->invalid('Enter a valid PAN, for example ABCDE1234F.') : $this->unavailable();
    }

    public function gstin(string $gstin): array
    {
        if (! preg_match(self::GSTIN, $gstin)) {
            return $this->invalid('Enter a valid 15-character GSTIN.');
        }
        if (EnforcementSwitches::skipThirdParty()) {
            return $this->valid('GSTIN verified.');
        }

        $r = $this->call(fn () => $this->cashfree->gstin($gstin));
        if ($r === null) {
            return $this->unavailable();
        }
        ['status' => $status, 'json' => $j] = $r;

        if ($status === 200) {
            if (($j['valid'] ?? false) !== true) {
                return $this->invalid('This GSTIN was not found.');
            }
            $gst = (string) ($j['gst_in_status'] ?? '');
            if ($gst !== '' && strcasecmp($gst, 'Active') !== 0) {
                return $this->invalid('This GSTIN is '.Str::lower($gst).', not active.');
            }

            return $this->valid('GSTIN verified.', array_filter(['name' => $j['legal_name_of_business'] ?? null, 'status' => $gst ?: null]));
        }

        return $status === 400 && str_starts_with((string) ($j['code'] ?? ''), 'GSTIN') ? $this->invalid('Enter a valid 15-character GSTIN.') : $this->unavailable();
    }

    public function ifsc(string $ifsc): array
    {
        if (! preg_match(self::IFSC, $ifsc)) {
            return $this->invalid('IFS Code must be 11 characters: 4 capital letters, then 0, then 6 letters or digits.');
        }
        if (EnforcementSwitches::skipThirdParty()) {
            return $this->valid('IFS Code verified.');
        }

        $r = $this->call(fn () => $this->cashfree->ifsc($ifsc));
        if ($r === null) {
            return $this->unavailable();
        }
        ['status' => $status, 'json' => $j] = $r;

        if ($status === 200 && strtoupper((string) ($j['status'] ?? 'VALID')) === 'VALID') {
            $modes = [];
            foreach (['neft' => 'NEFT', 'rtgs' => 'RTGS', 'imps' => 'IMPS', 'upi' => 'UPI'] as $key => $label) {
                if (strcasecmp((string) ($j[$key] ?? ''), 'Live') === 0) {
                    $modes[] = $label;
                }
            }

            return $this->valid('IFS Code verified.', array_filter([
                'bank' => $j['bank'] ?? null, 'branch' => $j['branch'] ?? null, 'address' => $j['address'] ?? null, 'city' => $j['city'] ?? null,
                'state' => $j['state'] ?? null, 'micr' => isset($j['micr']) ? (string) $j['micr'] : null, 'modes' => $modes ?: null,
            ]));
        }

        return in_array($status, [404, 400], true) && in_array($j['code'] ?? '', ['ifsc_not_found', 'ifsc_value_invalid'], true)
            ? $this->invalid('This IFS Code was not found.')
            : $this->unavailable();
    }

    /** Cashfree makes the penny drop and returns the holder's name; the account number and IFSC must be the ones the vendor saved. */
    public function bank(string $account, string $ifsc, ?string $name): array
    {
        if (! preg_match('/^[A-Z0-9]{6,40}$/', $account) || ! preg_match(self::IFSC, $ifsc)) {
            return $this->invalid('Check the account number and IFS Code.');
        }
        if (EnforcementSwitches::skipThirdParty()) {
            return $this->valid('Bank account verified.', ['reference' => 'DEMO-'.strtoupper(Str::random(10))]);
        }

        $r = $this->call(fn () => $this->cashfree->bankAccount($account, $ifsc, $name));
        if ($r === null) {
            return $this->unavailable();
        }
        ['status' => $status, 'json' => $j] = $r;
        $code = (string) ($j['account_status_code'] ?? $j['code'] ?? '');

        if ($status === 200) {
            if (strtoupper((string) ($j['account_status'] ?? '')) !== 'VALID') {
                return in_array(strtoupper($code), self::BAD_ACCOUNT, true) || $code === ''
                    ? $this->invalid('The bank could not confirm this account. Check the account number and IFS Code.')
                    : $this->unavailable();
            }

            $match = strtoupper((string) ($j['name_match_result'] ?? ''));
            if (config('cashfree.name_match') === 'reject_no_match' && $match === 'NO_MATCH') {
                return $this->invalid('The account holder name at the bank does not match the Account Name.', ['name_at_bank' => $j['name_at_bank'] ?? null]);
            }

            return $this->valid('Bank account verified.', array_filter([
                'name_at_bank' => $j['name_at_bank'] ?? null,
                'name_match' => $match ?: null,
                'bank' => $j['bank_name'] ?? null,
                'reference' => isset($j['reference_id']) ? 'CF-'.$j['reference_id'] : null,
            ]));
        }

        // 422 carries a code: the account is the problem (fraud / blocked ...) or the bank / network / our balance is
        if ($status === 422 && in_array(strtoupper($code), array_merge(self::BAD_ACCOUNT, ['FAILED_AT_BANK', 'SOURCE_BANK_DECLINED', 'BENE_BANK_DECLINED', 'IMPS_MODE_FAIL']), true)) {
            return $this->invalid('The bank could not confirm this account. Check the account number and IFS Code.');
        }
        if ($status === 400 && in_array($code, ['bank_account_value_invalid', 'bank_account_length_short', 'bank_account_length_exceeded', 'ifsc_value_invalid'], true)) {
            return $this->invalid('Check the account number and IFS Code.');
        }

        return $this->unavailable();
    }

    /** @return array{status:int,json:array<string,mixed>}|null null = no call could be made */
    private function call(callable $call): ?array
    {
        try {
            return $call();
        } catch (CashfreeException) {
            return null;
        }
    }

    private function valid(string $message, array $detail = []): array
    {
        return ['state' => 'valid', 'message' => $message, 'detail' => $detail];
    }

    private function invalid(string $message, array $detail = []): array
    {
        return ['state' => 'invalid', 'message' => $message, 'detail' => $detail];
    }

    private function unavailable(): array
    {
        return ['state' => 'unavailable', 'message' => 'We could not verify this right now. You can continue; it will be checked again later.', 'detail' => []];
    }
}
