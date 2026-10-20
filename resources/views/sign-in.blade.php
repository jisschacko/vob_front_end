@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
    <div class="card card-narrow">
        <img class="brand" src="{{ asset('images/lulu-hypermarket.jpg') }}" alt="Lulu">
        <h1>Sign in</h1>
        <p class="muted">
            Terms of Trade {{ $link['financial_year'] }}{{ ($link['type'] ?? null) === 'renew' ? ' (renewal)' : '' }}<br>
            <strong>{{ $link['vendor_name'] }}</strong>
            @if ($link['email_hint'])
                <br>Invitation sent to {{ $link['email_hint'] }}
            @endif
        </p>
        @if ($link['first_time_login'])
            <p class="hint">Enter the temporary password from your email. You will be asked to choose a new password next.</p>
        @else
            <p class="hint">Enter your Vendor Handshake password.</p>
        @endif

        <form method="POST" action="{{ route('sign-in') }}" autocomplete="on">
            @csrf
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" maxlength="254">
            @error('email') <p class="error" role="alert">{{ $message }}</p> @enderror

            <label for="password">{{ $link['first_time_login'] ? 'Temporary password' : 'Password' }}</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" maxlength="200">
            @error('password') <p class="error" role="alert">{{ $message }}</p> @enderror

            <button type="submit">Sign in</button>
        </form>
    </div>
@endsection
