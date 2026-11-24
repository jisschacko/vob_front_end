<?php

return [
    // Development-only switches (docs/API_CONTRACT.md 5A). Read them through App\Support\EnforcementSwitches, which forces
    // them on in production. Formats are validated regardless.
    'enforce_required' => filter_var(env('VOB_ENFORCE_REQUIRED', true), FILTER_VALIDATE_BOOLEAN),
    'enforce_documents' => filter_var(env('VOB_ENFORCE_DOCUMENTS', true), FILTER_VALIDATE_BOOLEAN),

    // Demo / testing: skip every third-party integration (Cashfree bank verification, S3 uploads). Ignored in production.
    'skip_third_party' => filter_var(env('VOB_SKIP_THIRD_PARTY', false), FILTER_VALIDATE_BOOLEAN),

    // Demo mode only (VOB_SKIP_THIRD_PARTY): these fields are not validated and always show as verified. See AppSupportDemoLenientFields.
    'demo_unvalidated_fields' => ['vendor_details.pan_no', 'vendor_details.gst_regn_no', 'bank.account_name', 'bank.account_no', 'bank.ifsc'],

    // Display-only wording for steps of the status tracker, by the step key the Admin sends (order, states and dates always come from the
    // API). Remove an entry once the Admin's own label says the same.
    'tracker_labels' => [
        'vendor' => 'Vendor Submission',
    ],

    // Timezone used when showing Admin API timestamps ("Draft saved 20-Oct-2026, 10:42 AM").
    'display_timezone' => env('VOB_DISPLAY_TIMEZONE', 'Asia/Kolkata'),

    // Vendor session limits, enforced by VOB (the Admin token has its own 30 min idle / 8 h absolute limits and wins if shorter).
    'session_idle_minutes' => (int) env('VOB_SESSION_IDLE_MINUTES', 30),
    'session_absolute_minutes' => (int) env('VOB_SESSION_ABSOLUTE_MINUTES', 480),

    // Vendor documents: the shared network folder (disk 'documents' = storage/app/documents -> /data/documents). Disk 's3' still works if set. Tests use a fake disk.
    'docs_disk' => env('VOB_DOCS_DISK', 'documents'),
    'docs_max_kb' => 5120,
    // Upload inspection (App\Services\Documents\UploadInspector): picture limits against decompression bombs, docx limits against zip bombs.
    'docs_image_max_side' => 10000,
    'docs_image_max_pixels' => 24000000,
    'docs_docx_max_entries' => 1500,
    'docs_docx_max_uncompressed_mb' => 60,
    // Extra files in Vendor Details (type additional_document): at most this many per TOT.
    'docs_additional_max' => 5,

    // Admin /masters data changes rarely; cache it (not vendor specific) for this many minutes.
    'masters_cache_minutes' => (int) env('VOB_MASTERS_CACHE_MINUTES', 10),

    // Browser -> VOB encryption of the sign-in forms (App\Services\Security\ClientEncryption). The owner creates this key pair (it is NOT the
    // Admin API key): the page gets the public key, the private key stays on the server. Without both files the forms post as before, except
    // in production, where the sign-in pages stay closed.
    'client_encryption' => [
        'public_key_path' => env('VOB_LOGIN_RSA_PUBLIC_KEY_PATH'),
        'private_key_path' => env('VOB_LOGIN_RSA_PRIVATE_KEY_PATH'),
    ],
];
