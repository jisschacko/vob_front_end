<?php

namespace App\Services\Documents;

/** Default: no scanner configured (the S3 bucket / WAF side may scan). Replace via the container when an AV engine is available. */
class NullScanner implements DocumentScanner
{
    public function isClean(string $path): bool
    {
        return true;
    }
}
