<?php

namespace App\Services\Documents;

/** Hook for an antivirus scan of an uploaded document. Return false to reject the file. Bind a real scanner in a service provider. */
interface DocumentScanner
{
    public function isClean(string $path): bool;
}
