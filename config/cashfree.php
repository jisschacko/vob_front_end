<?php

// Cashfree Verification Suite: PAN, GSTIN, IFSC and bank account (Cashfree's own penny drop, run in the background). Credentials come from .env only.
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

    // The bank account is checked by Cashfree's own penny drop (FieldVerifier), in the background when the vendor leaves the field.
    // Name at the bank vs the Account Name:
    // 'reject_no_match' (default) = the account is not verified when Cashfree reports NO_MATCH; 'ignore' = the result is only shown.
    'name_match' => env('CASHFREE_NAME_MATCH', 'reject_no_match'),
];
