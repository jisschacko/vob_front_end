<?php

namespace App\Services\AdminApi;

use RuntimeException;

/** Base for every failure talking to the Admin API. */
class AdminApiException extends RuntimeException {}
