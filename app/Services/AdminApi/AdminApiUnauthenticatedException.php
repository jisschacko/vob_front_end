<?php

namespace App\Services\AdminApi;

/** 401 from Admin: the session is over, send the vendor back to login. The stored token is already dropped. */
class AdminApiUnauthenticatedException extends AdminApiException {}
