@extends('layouts.auth')

@section('title', 'Sign in')

@section('content')
    <div class="auth-head">
        <h1>Welcome back</h1>
        <p>Sign in to your PartnerOne account</p>
    </div>
    <!--p class="hint">First time? Open the link in your invitation email, or sign in here with the temporary password from that email. You will be asked to choose a new password.</p-->

    <form method="POST" action="{{ route('login') }}" class="auth-form" autocomplete="on">
        @csrf
        <div class="auth-field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="name@company.com" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="254">
            @error('email') <p class="auth-error" role="alert">{{ $message }}</p> @enderror
        </div>

        @include('auth._password', ['id' => 'password', 'name' => 'password', 'label' => 'Password', 'autocomplete' => 'current-password', 'placeholder' => 'Enter your password', 'forgot' => [route('password.forgot'), 'Forgot password?']])

        <button type="submit" class="auth-submit">
            <span>Sign in</span>
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="m13 6 6 6-6 6"></path></svg>
        </button>
    </form>
@endsection

@push('scripts')
    <script src="{{ asset('js/auth.js') }}"></script>
@endpush
