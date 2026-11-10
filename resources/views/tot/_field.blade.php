@php
    /** @var \App\Services\Tot\TotPage $page */
    $path = $f['path'];
    $type = $f['type'];
    $bare = $f['bare'] ?? false;
    $locked = $page->isLocked($path);
    $name = $page->name($path);
    $id = $page->id($path).($f['idsuffix'] ?? '');
    $val = $page->value($path);
    $err = $page->error($path);
    $max = $f['maxlength'] ?? 200;
    $masked = $page->isMasked($path);
    $listed = in_array($path, $page->requiredFields(), true);
    $inputType = ['line' => 'text', 'tel' => 'tel', 'email' => 'email', 'number' => 'number', 'date' => 'date'][$type] ?? 'text';
    $demoVerified = $page->demoVerified($path);
    $verify = $page->verifyKind($path); // pan | gstin | ifsc | bank: checked by Cashfree in the background when the vendor leaves the box
    $verifyUrl = ['pan' => 'verify.pan', 'gstin' => 'verify.gstin', 'ifsc' => 'verify.ifsc'][$verify ?? ''] ?? ($path === 'bank.account_no_confirm' ? 'verify.bank' : null);
    $class = 'f'.(($f['inline'] ?? false) ? ' inline-f' : '').($demoVerified && filled($val) && ! $err ? ' is-valid' : '');
@endphp
@unless ($bare)
<div class="field-wrap {{ $locked && $page->editable() ? 'locked' : '' }} {{ in_array($type, ['company_codes', 'purchase_orgs'], true) ? 'span-all' : '' }}">
    <label class="flabel" for="{{ $id }}">{{ $f['label'] }}
        @if ($listed)<span class="req" @if ($path === 'vendor_details.gst_regn_no') id="gst-regn-req" @endif @unless ($page->isRequired($path)) hidden @endunless>*</span>@endif
        @if ($locked && $page->editable())<span class="tag-readonly">(Filled by Lulu)</span>@endif
    </label>
@endunless

@switch($type)
    @case('company_codes')
    @case('purchase_orgs')
        <div class="chip-box disabled" id="{{ $id }}">
            @forelse ($page->selectedCodes($type) as $code)
                <span class="chip" title="{{ $page->masterOptions($type)[$code] ?? '' }}"><span class="chip-code">{{ $code }}</span></span>
            @empty
                <span class="muted">Not assigned yet.</span>
            @endforelse
        </div>
        @break

    @case('text')
        <textarea class="{{ $class }} autogrow" id="{{ $id }}" name="{{ $name }}" rows="1" maxlength="{{ $max }}" @if ($demoVerified) data-demo-valid @endif @if ($path === 'bank.bank_name_address') data-ifsc-fill @endif @if ($verify) data-verify="{{ $verify }}" @endif
            @if ($locked) disabled @endif @isset($f['placeholder']) placeholder="{{ $f['placeholder'] }}" @endisset>{{ $val }}</textarea>
        @break

    @case('select')
        <select class="{{ $class }}" id="{{ $id }}" name="{{ $name }}" @if ($locked) disabled @endif @if ($path === 'vendor_details.gst_type') data-gst-type @endif @if ($path === 'vendor_details.scope_type') data-scope-type @endif>
            <option value=""></option>
            @foreach ($page->options($f) as $code => $label)
                <option value="{{ $code }}" @selected((string) $val === (string) $code)>{{ $label }}</option>
            @endforeach
        </select>
        @break

    @case('scope_state')
        <select class="{{ $class }}" id="{{ $id }}" name="{{ $name }}" data-scope-state data-scope-options="{{ json_encode($page->scopeOptions(), JSON_UNESCAPED_UNICODE) }}" @if ($locked || $page->raw('vendor_details.scope_type') === 'PAN India') disabled @endif>
            <option value=""></option>
            @foreach ($page->scopeStateOptions($path) as $code => $label)
                <option value="{{ $code }}" @selected((string) $val === (string) $code)>{{ $label }}</option>
            @endforeach
        </select>
        @break

    @case('bool')
        <select class="{{ $class }}" id="{{ $id }}" name="{{ $name }}" @if ($locked) disabled @endif>
            <option value=""></option>
            <option value="Yes" @selected($val === 'Yes' || $val === '1')>Yes</option>
            <option value="No" @selected($val === 'No' || $val === '0')>No</option>
        </select>
        @break

    @case('check')
        @unless ($locked)<input type="hidden" name="{{ $name }}" value="0">@endunless
        <label class="checkbox-row"><input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1" @checked(in_array($val, ['Yes', '1', 1, true], true)) @if ($locked) disabled @endif>
            @if ($bare) <span class="sr-only">{{ $f['label'] ?? '' }}</span> @endif
            @if (! $bare && isset($f['help']))<span class="help">{{ $f['help'] }}</span>@endif</label>
        @break

    @case('confirm')
        {{-- Typed again to catch mistakes; masked, never pre-filled and never sent to the Admin (it is not a form field there). --}}
        <input class="{{ $class }}" type="password" id="{{ $id }}" name="{{ $name }}" maxlength="{{ $max }}" autocomplete="off" data-confirm-of="{{ $page->id('bank.account_no') }}" @if ($verify) data-verify="{{ $verify }}" @endif @if ($verifyUrl) data-verify-url="{{ route($verifyUrl) }}" @endif @if ($locked) disabled @endif>
        @break

    @default
        <input class="{{ $class }}" type="{{ $inputType }}" id="{{ $id }}" name="{{ $name }}" maxlength="{{ $max }}"
            @if ($inputType === 'number') min="0" @isset($f['max']) max="{{ $f['max'] }}" @endisset step="any" @endif
            @if ($inputType === 'date') value="{{ substr((string) $val, 0, 10) }}" @else value="{{ $val }}" @endif
            @if ($masked) placeholder="{{ $page->raw($path) }} (leave blank to keep)" autocomplete="off" @endif
            @isset($f['ifsc']) data-ifsc autocapitalize="characters" spellcheck="false" @endisset
            @isset($f['aria']) aria-label="{{ $f['aria'] }}" @endisset
            @isset($f['sync']) data-sync="{{ $f['sync'] }}" @endisset
            @if ($demoVerified) data-demo-valid @endif
            @if ($verify) data-verify="{{ $verify }}" @endif @if ($verifyUrl) data-verify-url="{{ route($verifyUrl) }}" @endif
            @if ($locked) disabled @endif>
@endswitch

{{-- Result of the background Cashfree check (filled in by field-verify.js). The bank check reports under Confirm Account No. --}}
@if ($verifyUrl)<p class="verify-msg" role="status" data-verify-msg hidden></p>@endif
{{-- Green tick for the saved, verified bank values (from the Admin's verification_status); field-verify.js hides it when they are edited --}}
@if (in_array($path, ['bank.account_no', 'bank.ifsc'], true) && ($verify || $page->bankStatus() === 'verified'))
    <p class="verify-msg is-valid" data-bank-ok @unless ($page->bankStatus() === 'verified' && filled($page->raw($path))) hidden @endunless>&#10003; Verified</p>
@endif
@if ($verify === 'ifsc')<p class="verify-detail" data-verify-detail hidden></p>@endif

@if ($demoVerified)<p class="valid-badge" data-demo-valid-badge @if (! filled($val) || $err) hidden @endif>&#10003; Verified</p>@endif
@if ($err)<p class="error" role="alert">{{ $err }}</p>@endif
@if (isset($f['ifsc']) || $type === 'confirm')<p class="error" role="alert" data-live-error hidden></p>@endif
@if ($type === 'confirm')<p class="valid-badge" data-live-ok hidden>&#10003; Account number matched</p>@endif
@unless ($bare)
    @if (isset($f['help']) && $type !== 'check')<p class="help">{{ $f['help'] }}</p>@endif
</div>
@endunless
