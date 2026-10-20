<?php

// Cashfree Verification Suite: Reverse Penny Drop (bank account verification). Credentials come from .env only.
return [
    'client_id' => env('CASHFREE_CLIENT_ID'),
    'client_secret' => env('CASHFREE_CLIENT_SECRET'),

    // 'sandbox' (test credentials) or 'production' (live credentials; test credentials are rejected there)
    'env' => env('CASHFREE_ENV', 'sandbox'),
    'base_urls' => [
        'sandbox' => 'https://sandbox.cashfree.com/verification',
        'production' => 'https://api.cashfree.com/verification',
    ],

    'timeout' => (int) env('CASHFREE_TIMEOUT', 15),

    // The UPI link / QR code is valid for 10 minutes (Cashfree). Used when valid_upto is not returned.
    'link_minutes' => 10,

    // Account number and IFSC of the paying account must always match the entered bank details.
    // 'ignore' = the name match result is only logged; 'reject_no_match' = also fail when Cashfree reports NO_MATCH.
    'name_match' => env('CASHFREE_NAME_MATCH', 'ignore'),

    // Rows in cashfree_verifications are temporary: purged this many days after they expire.
    'purge_after_days' => (int) env('CASHFREE_PURGE_AFTER_DAYS', 2),
];
