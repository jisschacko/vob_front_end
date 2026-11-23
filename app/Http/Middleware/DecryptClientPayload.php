<?php

namespace App\Http\Middleware;

use App\Services\Security\ClientEncryption;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign-in forms post their credentials as one encrypted field (`enc`, see ClientEncryption and public/js/secure-form.js). This opens it and
 * puts the fields back on the request, so the controller and its validation are unchanged. Usage: `client.decrypt:email,password`.
 *
 * With keys configured the readable fields are refused (a browser that cannot encrypt cannot sign in). Without keys the form works as before,
 * except in production, where the sign-in pages stay closed rather than accept readable credentials.
 */
class DecryptClientPayload
{
    public function __construct(private readonly ClientEncryption $encryption) {}

    public function handle(Request $request, Closure $next, string ...$fields): Response
    {
        if (! $this->encryption->enabled()) {
            return $this->encryption->required() ? response()->view('errors.service-unavailable', [], 503) : $next($request);
        }

        $enc = $request->input('enc');
        $opened = is_string($enc) && $enc !== '' && ! $request->hasAny($fields) ? $this->encryption->open($enc, $fields) : null;

        if ($opened === null) {
            return back()->withErrors(['email' => 'We could not secure your sign-in. Please reload the page and try again.']);
        }

        $request->request->remove('enc');
        $request->merge($opened);

        return $next($request);
    }
}
