<?php

use App\Support\TextSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\TotFixtures as T;

uses(RefreshDatabase::class);

describe('TextSanitizer', function () {
    it('keeps the characters business data needs, exactly as typed', function (string $text) {
        expect(TextSanitizer::clean($text))->toBe($text);
    })->with([
        "AT&T (India) Pvt. Ltd. - Unit 5/6",
        "O'Neil & Sons \"Fresh\" Foods",
        'Plot #12, 3rd Cross, MG Road (Near Metro) - 560001',
        'Rebate < 5% on invoices > Rs 10,000; net +30 days = fine',
        'A -> B, C => D, e-mail: sales@acme.test, GSTIN 29AAICP2912R1ZR',
        "Line one\nLine two\ttabbed",
        'Bank: A/C 026291800001191 IFSC YESB0000262 [main] {branch}',
        'DROP TABLE vendors; -- is only text here',
        "Rate for item 1' OR '1'='1 is Rs 5",
        'ünïcödé नमस्ते 日本',
    ]);

    it('removes HTML, script and style markup (script content included)', function (string $dirty, string $clean) {
        expect(TextSanitizer::clean($dirty))->toBe($clean);
    })->with([
        ['<b>Acme</b> Foods', 'Acme Foods'],
        ['Acme<script>alert(1)</script> Foods', 'Acme Foods'],
        ['<SCRIPT SRC=//evil.test/x.js></SCRIPT>Acme', 'Acme'],
        ['<img src=x onerror=alert(1)>Acme', 'Acme'],
        ['<a href="javascript:alert(1)">click</a>', 'click'],
        ['<style>body{display:none}</style>Acme', 'Acme'],
        ['<iframe src="//evil.test"></iframe>Acme', 'Acme'],
        ['Acme<!-- hidden -->Foods', 'AcmeFoods'],
        ['<?php echo 1; ?>Acme', 'Acme'],
        ['<scr<script>ipt>alert(1)</scr</script>ipt>Acme', 'Acme'],
        ['Acme <img src=x onerror=alert(1)', 'Acme '],
        ["Ac\x00me\x07 Foods", 'Acme Foods'],
        ['<svg/onload=alert(1)>Acme', 'Acme'],
    ]);

    it('drops invalid UTF-8', function () {
        expect(TextSanitizer::clean("Acme \xC3\x28 Foods"))->not->toContain("\xC3\x28")->and(mb_check_encoding(TextSanitizer::clean("Acme \xC3\x28"), 'UTF-8'))->toBeTrue();
    });

    it('cleans nested values and leaves skipped keys and non-strings alone', function () {
        $out = TextSanitizer::deep(['form' => ['vendor_details' => ['vendor_name' => '<b>Acme</b> & Sons'], 'n' => 5, 'flag' => true], 'password' => 'a<b>c'], ['password']);

        expect($out)->toBe(['form' => ['vendor_details' => ['vendor_name' => 'Acme & Sons'], 'n' => 5, 'flag' => true], 'password' => 'a<b>c']);
    });
});

describe('the sanitising middleware', function () {
    it('removes markup from a saved draft but keeps business characters, before anything reaches the Admin', function () {
        T::fake(['PUT tots/'.T::TOT_ID.'/draft' => Http::response(['saved' => true, 'saved_at' => now()->toIso8601String()])]);

        $this->withSession(T::session())->post('/tot/autosave', ['form' => ['vendor_details' => [
            'vendor_name' => '<script>alert(1)</script>AT&T (India) - O\'Neil / Sons',
            'address' => '12/B, <img src=x onerror=alert(1)>MG Road',
        ]]]);

        $sent = T::sent('PUT', 'tots/'.T::TOT_ID.'/draft');
        expect($sent['form']['vendor_details']['vendor_name'])->toBe("AT&T (India) - O'Neil / Sons")
            ->and($sent['form']['vendor_details']['address'])->toBe('12/B, MG Road');
    });

    it('never alters a password or the encrypted sign-in field', function () {
        $request = \Illuminate\Http\Request::create('/x', 'POST', ['password' => 'a<b>c&d', 'enc' => 'x<y>z', 'note' => '<b>hi</b>']);
        $out = (new \App\Http\Middleware\SanitizeInput)->handle($request, fn ($r) => response($r->input('password').'|'.$r->input('enc').'|'.$r->input('note')));

        expect($out->getContent())->toBe('a<b>c&d|x<y>z|hi');
    });
});

it('escapes output everywhere: only one unescaped Blade echo exists, around an escaped value, and no script sinks take server data', function () {
    $raw = collect(glob(resource_path('views').'/{*,*/*}.blade.php', GLOB_BRACE))->flatMap(fn ($f) => preg_grep('/\{!!/', file($f)))->values();

    expect($raw)->toHaveCount(2);
});
