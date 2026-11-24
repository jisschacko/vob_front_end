<?php

use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

// Tests must not depend on the developer's .env: the sign-in encryption keys and the documents disk are set by the tests that need them.
pest()->beforeEach(function () {
    config([
        'vob.client_encryption.public_key_path' => null,
        'vob.client_encryption.private_key_path' => null,
        'vob.docs_disk' => 'documents',
    ]);
})->in('Feature');
