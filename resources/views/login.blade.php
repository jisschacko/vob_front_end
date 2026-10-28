@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
    <div class="card card-narrow">
        <img class="brand brand-login"src="{{ asset('images/lulu-hypermarket.jpg') }}" alt="Lulu">
        <h1>Sign in</h1>
        <p class="muted">Use the email address and password of your vendor account.</p>
        <!--p class="hint">First time? Open the link in your invitation email, or sign in here with the temporary password from that email. You will be asked to choose a new password.</p-->

        <form method="POST" action="{{ route('login') }}" autocomplete="on">
            @csrf
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" maxlength="254">
            @error('email') <p class="error" role="alert">{{ $message }}</p> @enderror

            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" maxlength="200">
            @error('password') <p class="error" role="alert">{{ $message }}</p> @enderror

            <button type="submit">Sign in</button>
        </form>
    </div>
@endsection
