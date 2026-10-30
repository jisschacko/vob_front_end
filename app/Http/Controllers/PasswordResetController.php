<?php

namespace App\Http\Controllers;

use App\Services\AdminApi\AdminApiClient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** Forgot password (docs/API_CONTRACT.md "Forgot password"). The Admin owns the token, the e-mail and the password rules. */
class PasswordResetController extends Controller
{
    private const TOKEN_KEY = 'password_reset.token';

    private const NEUTRAL = 'If the address belongs to a vendor account, an email with a reset link has been sent.';

    public function forgotForm()
    {
        return view('forgot-password');
    }

    public function forgotStore(Request $request, AdminApiClient $api)
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);

        $response = $api->forgotPassword($data['email']);

        if ($response->successful()) {
            // always the same words, whether or not the address belongs to a vendor
            return redirect()->route('password.forgot')->with('status', self::NEUTRAL);
        }

        return back()->withInput($request->only('email'))->withErrors(['email' => $response->status() === 429
            ? 'Too many requests, try again later.'
            : 'We could not process this request right now. Please try again later.']);
    }

    /** The emailed link: the token is moved into the server-side session at once, so it is not left in the address bar or the history. */
    public function link(Request $request, string $token)
    {
        $request->session()->put(self::TOKEN_KEY, $token);

        return redirect()->route('password.reset');
    }

    public function resetForm(Request $request)
    {
        if (! $request->session()->has(self::TOKEN_KEY)) {
            return $this->expired($request);
        }

        return view('reset-password');
    }

    public function resetStore(Request $request, AdminApiClient $api)
    {
        $token = $request->session()->get(self::TOKEN_KEY);
        if (! is_string($token) || $token === '') {
            return $this->expired($request);
        }

        // same strength rules as the Admin (it re-checks): >= 10 chars, upper + lower case, number, symbol
        $data = $request->validate([
            'new_password' => ['required', 'string', 'confirmed', 'max:200', Password::min(10)->mixedCase()->numbers()->symbols()],
        ]);

        $response = $api->resetPassword($token, $data['new_password']);

        if ($response->successful()) {
            $request->session()->forget(self::TOKEN_KEY);

            return redirect()->route('login')->with('status', 'Your password has been changed. Please sign in.');
        }

        if ($response->status() === 422) {
            if ($response->json('error') === 'token_invalid') { // wrong, already used or expired: one answer for all three
                return $this->expired($request);
            }

            // weak password: the token is not used up, so the vendor can try again on this page
            $message = $response->json('errors.new_password.0') ?? $response->json('errors.new_password');

            return back()->withErrors(['new_password' => is_string($message) ? $message : 'This password is not allowed. Please choose another.']);
        }

        return back()->withErrors(['new_password' => $response->status() === 429
            ? 'Too many attempts. Please try again later.'
            : 'We could not change the password right now. Please try again later.']);
    }

    private function expired(Request $request)
    {
        $request->session()->forget(self::TOKEN_KEY);

        return redirect()->route('password.forgot')
            ->withErrors(['email' => 'This link is invalid or has expired. Ask for a new one below.']);
    }
}
