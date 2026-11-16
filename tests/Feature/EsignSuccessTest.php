<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

describe('e-sign success landing page (eMudhra redirect)', function () {
    it('is public: no login, no session, no cookies, no API call', function () {
        Http::fake();

        $response = $this->get('/esign/success')->assertOk();

        expect($response->headers->getCookies())->toBe([]);
        Http::assertNothingSent();
        $html = $response->getContent();
        expect($html)->toContain('TOT signed successfully')->toContain('Your signature has been recorded')->toContain('You can now close this window.')
            ->toContain('/css/esign-success.css');
    });

    it('does not name the signer and ignores whatever query values eMudhra appends', function () {
        $html = $this->get('/esign/success?status=ok&signer=Asha&token=SECRET123&email=a@b.test')->assertOk()->getContent();

        expect($html)->not->toContain('SECRET123')->not->toContain('Asha')->not->toContain('a@b.test');
        expect(\App\Models\ApiLog::count())->toBe(0);
    });

    it('uses no inline script or style (CSP) and respects reduced motion', function () {
        $html = $this->get('/esign/success')->getContent();

        expect($html)->not->toMatch('/\sstyle="|\sonclick=/i')->not->toMatch('/<script(?![^>]*\ssrc=)/i')->toContain('viewport');
        $css = file_get_contents(public_path('css/esign-success.css'));
        expect($css)->toContain('prefers-reduced-motion: reduce')->toContain('prefers-color-scheme: dark');
    });
});
