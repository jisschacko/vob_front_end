<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TotFixtures as T;

uses(RefreshDatabase::class);

// The bank account is verified in the background (see FieldVerificationTest). These tests cover what stays on the form page.

describe('the form page', function () {
    it('has no "Verify bank account" button or QR form: the bank account is verified in the background, and the status line stays', function () {
        T::fake();
        $html = $this->withSession(T::session())->get('/home')->getContent();

        expect($html)->not->toContain('data-bank-verify')->not->toContain('id="bank-verify"')->not->toContain('Verify bank account')
            ->toContain('Bank account verification:')->toContain('Pending');

        T::fake(detail: T::detail(['form' => ['bank' => ['verification_status' => 'verified']]]));
        expect($this->withSession(T::session())->get('/home')->getContent())->toContain('<strong>Verified</strong>')->not->toContain('Verify again');
    });

    it('no longer has the old UPI-scan routes or webhook', function () {
        $this->withSession(T::session())->post('/bank/verify')->assertNotFound();
        $this->withSession(T::session())->get('/bank/verify')->assertNotFound();
        $this->post('/webhooks/cashfree')->assertNotFound();
        expect(file_exists(public_path('js/bank-verify.js')))->toBeFalse();
    });
});
