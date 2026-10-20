<?php

namespace App\Http\Controllers;

use App\Services\AdminApi\AdminApiClient;
use App\Services\AdminApi\AdminSession;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function create(AdminSession $session)
    {
        if ($session->check()) {
            return redirect()->route('home');
        }

        $link = $session->link();
        if ($link === null) {
            return redirect('/'); // no invitation link opened in this browser session
        }

        return view('sign-in', ['link' => $link]);
    }

    public function store(Request $request, AdminApiClient $api, AdminSession $session)
    {
        $link = $session->link();
        if ($link === null) {
            return redirect('/');
        }

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $response = $api->login($data['email'], $data['password'], $link['token']);

        if ($response->successful() && $session->check()) {
            return $session->mustChangePassword()
                ? redirect()->route('password.edit')
                : redirect()->route('home');
        }

        if ($response->status() === 422) { // credentials fine, link wrong / for another vendor / replaced
            $session->forgetLink();

            return redirect()->route('link.invalid');
        }

        $message = match ($response->status()) {
            401 => 'The email or password is not correct.',
            429 => 'Too many unsuccessful attempts. Please wait 15 minutes and try again.',
            default => 'We could not sign you in right now. Please try again later.',
        };

        return back()->withInput($request->only('email'))->withErrors(['email' => $message]);
    }

    public function destroy(Request $request, AdminApiClient $api)
    {
        $api->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', 'You have been signed out.');
    }
}
