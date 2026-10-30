@extends('layouts.app')

@section('title', 'Forgot password')

@section('content')
    <div class="card card-narrow">
        <img class="brand brand-login" src="{{ asset('images/lulu-hypermarket.jpg') }}" alt="Lulu">
        <h1>Forgot password</h1>
        <p class="muted">Enter the email address of your vendor account. If it belongs to an account, we will email you a link to choose a new password. The link works for 60 minutes.</p>

        <form method="POST" action="{{ route('password.email') }}" autocomplete="on">
            @csrf
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="254">
            @error('email') <p class="error" role="alert">{{ $message }}</p> @enderror

            <button type="submit">Send reset link</button>
        </form>
        <p class="form-links"><a href="{{ route('login') }}">Back to sign in</a></p>
    </div>
@endsection
