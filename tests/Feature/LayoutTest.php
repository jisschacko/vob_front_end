<?php

use App\Support\EnforcementSwitches;
use Illuminate\Support\Facades\Blade;
use Tests\Support\TotFixtures as T;

function sampleProgress(): array
{
    return ['current' => 'vendor', 'steps' => [
        ['key' => 'initiated', 'label' => 'TOT initiated', 'state' => 'done', 'entered_at' => '2026-10-20T09:00:00+00:00'],
        ['key' => 'vendor', 'label' => 'Vendor details & submission', 'state' => 'current', 'entered_at' => '2026-10-20T09:05:00+00:00'],
        ['key' => 'buyer', 'label' => 'Buyer review', 'state' => 'pending', 'entered_at' => null],
        ['key' => 'esign', 'label' => 'E-Sign', 'state' => 'pending', 'entered_at' => null],
    ]];
}

it('renders the tracker from the API progress: tick, current highlighted, rest pending, in order', function () {
    $html = Blade::render('<x-status-tracker :progress="$p" vendor-name="Acme Foods Pvt Ltd" subtitle="Terms of Trade 2026-27" draft-saved-at="2026-10-20T05:12:00+00:00" current-note="Action needed: see reviewer comments" />', ['p' => sampleProgress()]);

    expect($html)
        ->toContain('Acme Foods Pvt Ltd', 'Terms of Trade 2026-27')
        ->toContain('Draft saved 20-Oct-2026, 10:42 AM') // 05:12 UTC shown in Asia/Kolkata
        ->toContain('Action needed: see reviewer comments')
        ->toContain('20-Oct-2026')
        ->and(preg_match_all('/class="step (done|current|pending)"/', $html, $m))->toBe(4)
        ->and($m[1])->toBe(['done', 'current', 'pending', 'pending'])
        ->and($html)->toContain('aria-current="step"');

    expect(strpos($html, 'TOT initiated'))->toBeLessThan(strpos($html, 'Buyer review'))
        ->and(strpos($html, 'Buyer review'))->toBeLessThan(strpos($html, 'E-Sign'));
});

it('treats an unknown step state as pending and escapes labels', function () {
    $html = Blade::render('<x-status-tracker :progress="$p" />', ['p' => ['steps' => [['label' => '<script>x</script>', 'state' => 'weird']]]]);

    expect($html)->toContain('class="step pending"')->not->toContain('<script>x</script>');
});

it('shows the left tracker in the layout only when progress is supplied', function () {
    $with = view('landing', ['progress' => sampleProgress(), 'vendorName' => 'Acme'])->render();
    $without = view('landing')->render();

    expect($with)->toContain('class="tracker"')->toContain('Vendor Submission')
        ->and($without)->not->toContain('class="tracker"');
});

it('serves the landing page with hardening headers and no inline script', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'")->toContain("frame-ancestors 'none'")
        ->and($response->getContent())->not->toContain('<script');
});

describe('development switches', function () {
    it('are on by default', function () {
        expect(EnforcementSwitches::required())->toBeTrue()
            ->and(EnforcementSwitches::documents())->toBeTrue()
            ->and(EnforcementSwitches::developmentMode())->toBeFalse();
        $this->get('/')->assertDontSee('Development mode');
    });

    it('can be turned off outside production and show a banner', function () {
        config(['vob.enforce_required' => false, 'vob.enforce_documents' => false]);

        expect(EnforcementSwitches::required())->toBeFalse()
            ->and(EnforcementSwitches::documents())->toBeFalse();
        $this->get('/')->assertSee('Development mode')->assertSee('mandatory-field checks are OFF')->assertSee('document checks are OFF');
    });

    it('are forced on in production whatever the env says', function () {
        config(['vob.enforce_required' => false, 'vob.enforce_documents' => false]);
        app()->detectEnvironment(fn () => 'production');

        expect(EnforcementSwitches::required())->toBeTrue()
            ->and(EnforcementSwitches::documents())->toBeTrue()
            ->and(EnforcementSwitches::developmentMode())->toBeFalse();
    });
});

describe('page header', function () {
    it('shows a Sign out button (POST, with CSRF) at the top of every signed-in page', function () {
        T::fake();
        $html = $this->withSession(T::session())->get('/home')->getContent();

        expect($html)->toMatch('/<header class="page-header">\s*<span class="page-header-user">Acme<\/span>\s*<form method="POST" action="[^"]+\/logout" class="signout-form">\s*<input type="hidden" name="_token"/')
            ->toContain('class="signout">Sign out</button>')
            ->and(substr_count($html, 'Sign out'))->toBe(1); // no duplicate links lower down
    });

    it('is not shown to visitors who are not signed in', function () {
        $this->get('/')->assertDontSee('Sign out');
    });

    it('shows the logo 74px high inside a narrower left menu', function () {
        $css = file_get_contents(public_path('css/vob.css'));

        expect($css)->toMatch('/.tracker .logo img {[^}]*height:s*74px/')->toMatch('/.tracker { width:230px; flex:0 0 230px;/');
    });

    it('shows "Vendor Submission" for the vendor step, keeping every other label from the API', function () {
        $html = Blade::render('<x-status-tracker :progress="$p" />', ['p' => sampleProgress()]);

        expect($html)->toContain('Vendor Submission')->not->toContain('Vendor details &amp; submission')
            ->toContain('TOT initiated')->toContain('Buyer review')->toContain('E-Sign');
    });
});

it('keeps white text on the Submit / Resubmit and Save Draft buttons when hovered', function () {
    $css = file_get_contents(public_path('css/tot.css'));

    // a dark hover background must always come with white text (the secondary button has navy text by default)
    expect($css)->toMatch('/\.tot \.btn:hover \{[^}]*color:\s*#fff/')
        ->toMatch('/\.tot \.btn-secondary:hover \{[^}]*background:\s*var\(--navy\);[^}]*color:\s*#fff/');
});
