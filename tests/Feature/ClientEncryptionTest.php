<?php

use App\Models\ApiLog;
use App\Services\Security\ClientEncryption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\Rsa;
use Tests\Support\TotFixtures as T;

uses(RefreshDatabase::class);

/** What public/js/secure-form.js does, in PHP: AES-256-GCM over {f, n}, key wrapped with RSA-OAEP (SHA-1), base64url JSON {ek, iv, ct||tag}. */
function secureEnc(string $publicDerB64, array $fields, string $nonce): string
{
    $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split($publicDerB64, 64, "\n")."-----END PUBLIC KEY-----\n";
    $aes = random_bytes(32);
    $iv = random_bytes(12);
    $ct = openssl_encrypt(json_encode(['f' => $fields, 'n' => $nonce]), 'aes-256-gcm', $aes, OPENSSL_RAW_DATA, $iv, $tag);
    openssl_public_encrypt($aes, $wrapped, $pem, OPENSSL_PKCS1_OAEP_PADDING);

    return rtrim(strtr(base64_encode(json_encode(['ek' => base64_encode($wrapped), 'iv' => base64_encode($iv), 'ct' => base64_encode($ct.$tag)])), '+/', '-_'), '=');
}

/** The hidden field of a rendered sign-in page: [key, nonce, html]. */
function secureForm(string $path = '/login'): array
{
    $html = test()->get($path)->assertOk()->getContent();
    preg_match('/name="enc" value="" data-secure-key="([^"]+)" data-secure-nonce="([^"]+)"/', $html, $m);

    return [$m[1] ?? null, $m[2] ?? null, $html];
}

beforeEach(function () {
    $keys = Rsa::pair();
    $private = tempnam(sys_get_temp_dir(), 'vobpriv');
    file_put_contents($private, $keys['privatePem']);
    register_shutdown_function(fn () => @unlink($private));

    config(['admin_api.rsa_public_key_path' => $keys['publicPath'], 'vob.client_encryption.public_key_path' => $keys['publicPath'], 'vob.client_encryption.private_key_path' => $private]);
    T::fake(['POST vendor/login' => Http::response([
        'access_token' => T::TOKEN, 'token_type' => 'Bearer', 'expires_in' => 1800,
        'user' => ['id' => 'USERPUBLICID0001', 'name' => 'Acme', 'email' => 'sales@acme.test', 'must_change_password' => false], 'tot' => null, 'tots' => [],
    ])]);
});

describe('the encrypted sign-in form', function () {
    it('carries the public key and a one-time nonce, never a private key', function () {
        [$key, $nonce, $html] = secureForm();

        expect($key)->not->toBeNull()->and($nonce)->toHaveLength(48)
            ->and($html)->toContain('/js/secure-form.js')->not->toContain('PRIVATE KEY')->not->toMatch('/\sstyle="|\sonclick=/i')->not->toMatch('/<script(?![^>]*\ssrc=)/i');
        expect(file_exists(public_path('js/secure-form.js')))->toBeTrue();
    });

    it('can fall back to the bundled pure-JS library when the browser has no WebCrypto (Internet Explorer 11), same-origin and pinned', function () {
        [, , $html] = secureForm();

        expect($html)->toContain('data-secure-forge="')->toContain('/js/vendor/forge-1.3.1.min.js');
        $file = public_path('js/vendor/forge-1.3.1.min.js');
        expect(file_exists($file))->toBeTrue()->and(hash_file('sha256', $file))->toBe('dc67fd132427ad96c9666c844b39565413c40ddb1f2d063c53512fbf6d387dfd');
        expect(file_get_contents(public_path('js/secure-form.js')))->not->toMatch('/=>|\blet\b|\bconst\b/'); // ES5 only: IE11 cannot parse arrow functions
    });

    it('signs in from the encrypted field, with no readable e-mail or password posted', function () {
        [$key, $nonce] = secureForm();

        $this->post('/login', ['enc' => secureEnc($key, ['email' => 'sales@acme.test', 'password' => 'Own#Pass-123'], $nonce)])->assertRedirect(route('home'));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/vendor/login') && $r->hasHeader('X-Auth-Data')); // the Admin call is the unchanged encrypted envelope
        expect(ApiLog::all()->map(fn ($l) => json_encode($l->toArray()))->implode(' '))->not->toContain('Own#Pass-123');
    });

    it('refuses readable credentials when keys are configured', function () {
        secureForm();

        $this->from('/login')->post('/login', ['email' => 'sales@acme.test', 'password' => 'Own#Pass-123'])
            ->assertRedirect('/login')->assertSessionHasErrors(['email' => 'We could not secure your sign-in. Please reload the page and try again.']);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/vendor/login'));
    });

    it('refuses readable fields sent next to a valid encrypted field', function () {
        [$key, $nonce] = secureForm();

        $this->post('/login', ['enc' => secureEnc($key, ['email' => 'a@b.test', 'password' => 'x'], $nonce), 'password' => 'Other#Pass-1'])->assertSessionHasErrors('email');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/vendor/login'));
    });

    it('uses a nonce once: a replay of the same encrypted field is refused', function () {
        [$key, $nonce] = secureForm();
        $enc = secureEnc($key, ['email' => 'sales@acme.test', 'password' => 'Own#Pass-123'], $nonce);

        $this->post('/login', ['enc' => $enc])->assertRedirect(route('home'));
        $this->post('/login', ['enc' => $enc])->assertSessionHasErrors('email');
    });

    it('refuses a nonce this session never received, tampering, garbage and an expired form', function () {
        [$key, $nonce] = secureForm();

        $this->post('/login', ['enc' => secureEnc($key, ['email' => 'sales@acme.test', 'password' => 'x'], str_repeat('a', 48))])->assertSessionHasErrors('email');

        $enc = secureEnc($key, ['email' => 'sales@acme.test', 'password' => 'x'], $nonce);
        $this->post('/login', ['enc' => substr($enc, 0, -6).'AAAAAA'])->assertSessionHasErrors('email');
        $this->post('/login', ['enc' => 'not-an-envelope'])->assertSessionHasErrors('email');
        $this->post('/login', ['enc' => ['a' => 'b']])->assertSessionHasErrors('email');

        $this->travel(ClientEncryption::NONCE_TTL + 60)->seconds();
        $this->post('/login', ['enc' => $enc])->assertSessionHasErrors('email');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/vendor/login'));
    });

    it('takes only the agreed fields (e-mail and password) out of the envelope', function () {
        [$key, $nonce] = secureForm();

        $this->post('/login', ['enc' => secureEnc($key, ['email' => 'sales@acme.test', 'password' => 'Own#Pass-123', 'link_token' => 'sneaky'], $nonce)])->assertRedirect(route('home'));
        Http::assertNotSent(fn ($r) => str_contains(json_encode($r->data()), 'sneaky'));
    });
});

describe('without keys', function () {
    it('keeps the plain form outside production and renders no encrypted field', function () {
        config(['vob.client_encryption.public_key_path' => null, 'vob.client_encryption.private_key_path' => null]);

        expect($this->get('/login')->getContent())->not->toContain('data-secure-key');
        $this->post('/login', ['email' => 'sales@acme.test', 'password' => 'Own#Pass-123'])->assertRedirect(route('home'));
    });

    it('closes the sign-in pages in production instead of accepting readable credentials', function () {
        config(['vob.client_encryption.public_key_path' => null, 'vob.client_encryption.private_key_path' => null]);
        app()->detectEnvironment(fn () => 'production');

        $this->withSession(['_token' => 't'])->post('/login', ['_token' => 't', 'email' => 'sales@acme.test', 'password' => 'Own#Pass-123'])->assertStatus(503);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/vendor/login'));
    });
});
