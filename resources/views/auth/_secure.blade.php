{{-- Banking-style sign-in: with the VOB key pair configured the browser encrypts the fields into this one hidden input (public/js/secure-form.js).
     Without keys nothing is rendered and the form posts as before (never in production, see DecryptClientPayload). --}}
@php $secure = app(\App\Services\Security\ClientEncryption::class)->issue() @endphp
@if ($secure)
    <input type="hidden" name="enc" value="" data-secure-key="{{ $secure['key'] }}" data-secure-nonce="{{ $secure['nonce'] }}" data-secure-fields="email,password" data-secure-forge="{{ asset('js/vendor/forge-1.3.1.min.js') }}">
    <p class="auth-error" role="alert" data-secure-error hidden></p>
@endif
