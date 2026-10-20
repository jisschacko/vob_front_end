<?php

namespace App\Services\Cashfree;

use RuntimeException;

/** A Cashfree call failed. getMessage() is safe to show the vendor; the technical reason is only in api_logs. */
class CashfreeException extends RuntimeException {}
