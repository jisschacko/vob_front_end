<?php

namespace App\Support;

/**
 * VOB_ENFORCE_REQUIRED / VOB_ENFORCE_DOCUMENTS: false stops the front end blocking Submit on missing mandatory fields /
 * documents. Always on in production; format validation is never affected.
 */
class EnforcementSwitches
{
    public static function required(): bool
    {
        return app()->isProduction() || (bool) config('vob.enforce_required');
    }

    public static function documents(): bool
    {
        return app()->isProduction() || (bool) config('vob.enforce_documents');
    }

    /**
     * VOB_SKIP_THIRD_PARTY: demo / test switch. Skips Cashfree bank verification (reported as verified with a DEMO reference) and
     * stores documents on the local disk instead of S3. Never active in production.
     */
    public static function skipThirdParty(): bool
    {
        return ! app()->isProduction() && (bool) config('vob.skip_third_party');
    }

    public static function developmentMode(): bool
    {
        return ! self::required() || ! self::documents() || self::skipThirdParty();
    }
}
