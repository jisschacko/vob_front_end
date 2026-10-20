<?php

namespace App\Services\AdminApi;

/** Could not reach the Admin API (timeout, DNS, TLS...). */
class AdminApiUnavailableException extends AdminApiException {}
