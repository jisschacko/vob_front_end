{{-- A password field with the "show password" eye. $id, $name, $label, $autocomplete, optional $placeholder, $minlength, $autofocus, $forgot = [url, text]. --}}
<div class="auth-field">
    <div class="auth-label-row">
        <label for="{{ $id }}">{{ $label }}</label>
        @isset($forgot)<a href="{{ $forgot[0] }}">{{ $forgot[1] }}</a>@endisset
    </div>
    <div class="auth-pwd">
        <input id="{{ $id }}" name="{{ $name }}" type="password" class="has-toggle" required autocomplete="{{ $autocomplete }}" maxlength="200"
               @isset($placeholder) placeholder="{{ $placeholder }}" @endisset @isset($minlength) minlength="{{ $minlength }}" @endisset @if ($autofocus ?? false) autofocus @endif>
        <button type="button" class="auth-toggle" data-toggle-password="{{ $id }}" aria-label="Show password" hidden>
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
        </button>
    </div>
    @error($name) <p class="auth-error" role="alert">{{ $message }}</p> @enderror
</div>
