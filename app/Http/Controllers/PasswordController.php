<?php

namespace App\Http\Controllers;

use App\Services\AdminApi\AdminApiClient;
use App\Services\AdminApi\AdminSession;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    public function edit(AdminSession $session)
    {
        return view('password', ['forced' => $session->mustChangePassword()]);
    }

    public function update(Request $request, AdminApiClient $api)
    {
        // Same rules as the Admin (>= 10 chars, upper + lower case, number, symbol, different from current); the Admin re-checks.
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:200'],
            'new_password' => ['required', 'string', 'confirmed', 'max:200', 'different:current_password',
                Password::min(10)->mixedCase()->numbers()->symbols()],
        ]);

        $response = $api->changePassword($data['current_password'], $data['new_password']);

        if ($response->successful()) {
            return redirect()->route('home')->with('status', 'Your password has been changed.');
        }

        if ($response->status() === 422) {
            $errors = [];
            foreach (['current_password', 'new_password'] as $field) {
                if ($message = $response->json("errors.$field.0") ?? $response->json("errors.$field")) {
                    $errors[$field] = is_array($message) ? $message[0] : $message;
                }
            }

            return back()->withErrors($errors ?: ['new_password' => 'The password could not be changed.']);
        }

        return back()->withErrors(['current_password' => 'We could not change the password right now. Please try again later.']);
    }
}
