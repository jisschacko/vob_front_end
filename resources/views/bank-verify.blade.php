@extends('layouts.app')

@section('title', 'Verify bank account')

@push('head')
    <script src="{{ asset('js/bank-verify.js') }}" defer></script>
@endpush

@section('content')
    <div class="card card-narrow bank-verify" data-status-url="{{ route('bank.status') }}" data-home-url="{{ route('home') }}"
         data-expires="{{ $row->expires_at->toIso8601String() }}">
        <h1>Verify your bank account</h1>
        <p class="muted">Pay <strong>Rs 1</strong> by UPI from the bank account ending <strong>{{ $row->account_last4 }}</strong> that you entered on the form.
            The amount is refunded to you within 48 hours.</p>

        @if (! empty($links['qr_code']))
            <img class="qr" src="data:image/png;base64,{{ $links['qr_code'] }}" alt="UPI QR code to scan with your payment app">
        @endif

        @if (! empty($links['upi_link']))
            <p class="muted">On a phone, open your payment app:</p>
            <p class="upi-buttons">
                <a class="file-btn" href="{{ $links['upi_link'] }}">Any UPI app</a>
                @foreach (['gpay' => 'Google Pay', 'phonepe' => 'PhonePe', 'paytm' => 'Paytm', 'bhim' => 'BHIM'] as $key => $label)
                    @if (! empty($links[$key]))<a class="file-btn" href="{{ $links[$key] }}">{{ $label }}</a>@endif
                @endforeach
            </p>
        @endif

        <p class="hint" id="bank-status" role="status" aria-live="polite">Waiting for the payment...</p>
        <p class="muted">This code is valid for <span id="bank-countdown">10:00</span>. Keep this page open: it updates by itself.</p>

        <p>
            <button type="button" id="bank-check" class="file-btn">Check now</button>
            <a class="file-btn" href="{{ route('home') }}">Back to the form</a>
        </p>
    </div>
@endsection
