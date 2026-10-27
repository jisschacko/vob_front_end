<?php

namespace App\Support;

/**
 * Demo mode (VOB_SKIP_THIRD_PARTY): PAN, GST number, account name / number and IFSC are not validated and always show as verified.
 *
 * VOB never format-checks them. The Admin does not either while its own TOT_SKIP_THIRD_PARTY is on (docs/API_CONTRACT.md 5A), so whatever
 * is typed is stored and returned unchanged. If the two flags disagree the Admin still validates and its error is shown beside the field.
 * Never active in production (EnforcementSwitches::skipThirdParty() is false there).
 */
class DemoLenientFields
{
    public static function active(): bool
    {
        return EnforcementSwitches::skipThirdParty();
    }

    /** @return list<string> paths such as "vendor_details.pan_no" */
    public static function paths(): array
    {
        return array_values((array) config('vob.demo_unvalidated_fields', []));
    }

    public static function is(string $path): bool
    {
        return self::active() && in_array($path, self::paths(), true);
    }
}
