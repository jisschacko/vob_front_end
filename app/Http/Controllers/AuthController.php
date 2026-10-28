<?php

namespace App\Http\Controllers;

use App\Services\AdminApi\AdminApiClient;
use App\Services\AdminApi\AdminSession;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    /** First sign-in, from the emailed invitation link (the link was resolved by /onboard/{token}). */
    public function create(AdminSession $session)
    {
        if ($session->check()) {
            return redirect()->route('home');
        }

        $link = $session->link();
        if ($link === null) {
            return redirect()->route('login'); // no invitation link opened in this browser session
        }

        return view('sign-in', ['link' => $link]);
    }

    public function store(Request $request, AdminApiClient $api, AdminSession $session)
    {
        $link = $session->link();
        if ($link === null) {
            return redirect()->route('login');
        }

        $data = $this->credentials($request);

        $response = $api->login($data['email'], $data['password'], $link['token']);

        if ($response->successful() && $session->check()) {
            return $this->afterLogin($session);
        }

        if ($response->status() === 422) { // credentials fine, link wrong / for another vendor / replaced
            $session->forgetLink();

            return redirect()->route('link.invalid');
        }

        return $this->failed($request, $response->status());
    }

    /** Returning vendors: e-mail and password only, no invitation link (docs/API_CONTRACT.md "Returning vendors"). */
    public function loginForm(AdminSession $session)
    {
        if ($session->check()) {
            return redirect()->route('home');
        }

        return view('login');
    }

    public function loginStore(Request $request, AdminApiClient $api, AdminSession $session)
    {
        $data = $this->credentials($request);

        // no link_token: the first-login flow with the link is unchanged and still the documented way in for a new vendor
        $response = $api->login($data['email'], $data['password']);

        if ($response->successful() && $session->check()) {
            return $this->afterLogin($session);
        }

        return $this->failed($request, $response->status());
    }

    public function destroy(Request $request, AdminApiClient $api)
    {
        $api->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have been signed out.');
    }

    /** @return array{email:string,password:string} */
    private function credentials(Request $request): array
    {
        return $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:200'],
        ]);
    }

    /** A temporary password must be changed first; then /home picks the TOT (one: opens it, several: the list, none: a message). */
    private function afterLogin(AdminSession $session)
    {
        return $session->mustChangePassword() ? redirect()->route('password.edit') : redirect()->route('home');
    }

    /** One message for every failure: never say which part was wrong (wrong password, unknown, inactive and internal accounts look alike). */
    private function failed(Request $request, int $status)
    {
        $message = match ($status) {
            401 => 'The email or password is not correct.',
            429 => 'Too many unsuccessful attempts. Please wait 15 minutes and try again.',
            default => 'We could not sign you in right now. Please try again later.',
        };

        return back()->withInput($request->only('email'))->withErrors(['email' => $message]);
    }
}
