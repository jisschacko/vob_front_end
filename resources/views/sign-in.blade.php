@extends('layouts.auth')

@section('title', 'Sign in')

@section('content')
    <div class="auth-head">
        <h1>Welcome</h1>
        <p>Sign in to your PartnerOne account</p>
    </div>

    <div class="auth-msg auth-msg-info">
        Terms of Trade {{ $link['financial_year'] }}{{ ($link['type'] ?? null) === 'renew' ? ' (renewal)' : '' }}<br>
        <strong>{{ $link['vendor_name'] }}</strong>
        @if ($link['email_hint'])
            <br>Invitation sent to {{ $link['email_hint'] }}
        @endif
    </div>
    @if ($link['first_time_login'])
        <p class="auth-head-note">Enter the temporary password from your email. You will be asked to choose a new password next.</p>
    @else
        <p class="auth-head-note">Enter your Vendor Handshake password.</p>
    @endif

    <form method="POST" action="{{ route('sign-in') }}" class="auth-form" autocomplete="on">
        @csrf
        <div class="auth-field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="name@company.com" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="254">
            @error('email') <p class="auth-error" role="alert">{{ $message }}</p> @enderror
        </div>

        @include('auth._password', ['id' => 'password', 'name' => 'password', 'label' => $link['first_time_login'] ? 'Temporary password' : 'Password', 'autocomplete' => 'current-password', 'placeholder' => $link['first_time_login'] ? 'Temporary password from your email' : 'Enter your password'])

        <button type="submit" class="auth-submit">
            <span>Sign in</span>
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="m13 6 6 6-6 6"></path></svg>
        </button>
    </form>
@endsection

@push('scripts')
    <script src="{{ asset('js/auth.js') }}"></script>
@endpush
