@extends('layouts.app')

@section('title', 'Vendor Onboarding...')

@section('content')
    <div class="card">
        <h1>Vendor onboarding...</h1>
        <p>Please open the link in the invitation email sent by your Lulu contact.</p>
        <p class="muted">Already registered? <a href="{{ route('login') }}">Sign in</a> with your email and password.</p>
    </div>
@endsection
