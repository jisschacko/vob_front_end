@extends('layouts.app')

@section('title', 'Choose a new password')

@section('content')
    <div class="card card-narrow">
        <img class="brand brand-login" src="{{ asset('images/lulu-hypermarket.jpg') }}" alt="Lulu">
        <h1>Choose a new password</h1>
        <p class="muted">At least 10 characters, with upper and lower case letters, a number and a symbol.</p>

        <form method="POST" action="{{ route('password.reset.store') }}" autocomplete="on">
            @csrf
            <label for="new_password">New password</label>
            <input id="new_password" name="new_password" type="password" required autofocus autocomplete="new-password" minlength="10" maxlength="200">
            @error('new_password') <p class="error" role="alert">{{ $message }}</p> @enderror

            <label for="new_password_confirmation">Confirm new password</label>
            <input id="new_password_confirmation" name="new_password_confirmation" type="password" required autocomplete="new-password" maxlength="200">

            <button type="submit">Change password</button>
        </form>
        <p class="form-links"><a href="{{ route('login') }}">Back to sign in</a></p>
    </div>
@endsection
