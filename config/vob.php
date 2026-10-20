<?php

return [
    // Development-only switches (docs/API_CONTRACT.md 5A). Read them through App\Support\EnforcementSwitches, which forces
    // them on in production. Formats are validated regardless.
    'enforce_required' => filter_var(env('VOB_ENFORCE_REQUIRED', true), FILTER_VALIDATE_BOOLEAN),
    'enforce_documents' => filter_var(env('VOB_ENFORCE_DOCUMENTS', true), FILTER_VALIDATE_BOOLEAN),

    // Timezone used when showing Admin API timestamps ("Draft saved 20-Oct-2026, 10:42 AM").
    'display_timezone' => env('VOB_DISPLAY_TIMEZONE', 'Asia/Kolkata'),
];
