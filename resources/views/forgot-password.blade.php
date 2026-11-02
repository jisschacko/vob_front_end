@extends('layouts.auth')

@section('title', 'Forgot password')

@section('content')
    <div class="auth-head">
        <h1>Forgot password</h1>
        <p>Enter the email address of your vendor account. If it belongs to an account, we will email you a link to choose a new password. The link works for 60 minutes.</p>
    </div>

    <form method="POST" action="{{ route('password.email') }}" class="auth-form" autocomplete="on">
        @csrf
        <div class="auth-field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="name@company.com" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="254">
            @error('email') <p class="auth-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="auth-submit">
            <span>Send reset link</span>
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="m13 6 6 6-6 6"></path></svg>
        </button>
    </form>
    <p class="auth-links"><a href="{{ route('login') }}">Back to sign in</a></p>
@endsection
