@extends('layouts.app')

@section('title', 'Change password')

@section('content')
    <div class="card card-narrow">
        <h1>{{ $forced ? 'Choose a new password' : 'Change password' }}</h1>
        @if ($forced)
            <p class="hint">For your security you must replace the temporary password before continuing.</p>
        @endif
        <p class="muted">At least 10 characters, with upper and lower case letters, a number and a symbol, and different from your current password.</p>

        <form method="POST" action="{{ route('password.update') }}" autocomplete="off">
            @csrf
            <label for="current_password">Current password</label>
            <input id="current_password" name="current_password" type="password" required autocomplete="current-password" maxlength="200">
            @error('current_password') <p class="error" role="alert">{{ $message }}</p> @enderror

            <label for="new_password">New password</label>
            <input id="new_password" name="new_password" type="password" required autocomplete="new-password" minlength="10" maxlength="200">
            @error('new_password') <p class="error" role="alert">{{ $message }}</p> @enderror

            <label for="new_password_confirmation">Confirm new password</label>
            <input id="new_password_confirmation" name="new_password_confirmation" type="password" required autocomplete="new-password" maxlength="200">

            <button type="submit">Change password</button>
        </form>
    </div>
@endsection
