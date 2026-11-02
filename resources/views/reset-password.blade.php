@extends('layouts.auth')

@section('title', 'Choose a new password')

@section('content')
    <div class="auth-head">
        <h1>Choose a new password</h1>
        <p>At least 10 characters, with upper and lower case letters, a number and a symbol.</p>
    </div>

    <form method="POST" action="{{ route('password.reset.store') }}" class="auth-form" autocomplete="on">
        @csrf
        @include('auth._password', ['id' => 'new_password', 'name' => 'new_password', 'label' => 'New password', 'autocomplete' => 'new-password', 'minlength' => 10, 'autofocus' => true])
        @include('auth._password', ['id' => 'new_password_confirmation', 'name' => 'new_password_confirmation', 'label' => 'Confirm new password', 'autocomplete' => 'new-password'])

        <button type="submit" class="auth-submit">
            <span>Change password</span>
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="m13 6 6 6-6 6"></path></svg>
        </button>
    </form>
    <p class="auth-links"><a href="{{ route('login') }}">Back to sign in</a></p>
@endsection

@push('scripts')
    <script src="{{ asset('js/auth.js') }}"></script>
@endpush
