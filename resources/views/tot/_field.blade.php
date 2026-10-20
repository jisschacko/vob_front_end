@php
    /** @var \App\Services\Tot\TotPage $page */
    $path = $f['path'];
    $type = $f['type'];
    $bare = $f['bare'] ?? false;
    $locked = $page->isLocked($path);
    $name = $page->name($path);
    $id = $page->id($path);
    $val = $page->value($path);
    $err = $page->error($path);
    $max = $f['maxlength'] ?? 200;
    $masked = $page->isMasked($path);
    $listed = in_array($path, (array) ($page->detail['required_fields'] ?? []), true);
    $inputType = ['line' => 'text', 'tel' => 'tel', 'email' => 'email', 'number' => 'number', 'date' => 'date'][$type] ?? 'text';
    $class = 'f'.(($f['inline'] ?? false) ? ' inline-f' : '');
@endphp
@unless ($bare)
<div class="field-wrap {{ $locked && $page->editable() ? 'locked' : '' }} {{ $type === 'company_codes' ? 'span-all' : '' }}">
    <label class="flabel" for="{{ $id }}">{{ $f['label'] }}
        @if ($listed)<span class="req" @if ($path === 'vendor_details.gst_regn_no') id="gst-regn-req" @endif @unless ($page->isRequired($path)) hidden @endunless>*</span>@endif
        @if ($locked && $page->editable())<span class="tag-readonly">(set by Lulu)</span>@endif
    </label>
@endunless

@switch($type)
    @case('company_codes')
        <div class="chip-box disabled" id="{{ $id }}">
            @forelse ($page->selectedCodes('company_codes') as $code)
                <span class="chip" title="{{ $page->masterOptions('company_codes')[$code] ?? '' }}"><span class="chip-code">{{ $code }}</span></span>
            @empty
                <span class="muted">Not assigned yet.</span>
            @endforelse
        </div>
        @break

    @case('text')
        <textarea class="{{ $class }} autogrow" id="{{ $id }}" name="{{ $name }}" rows="1" maxlength="{{ $max }}"
            @if ($locked) disabled @endif @isset($f['placeholder']) placeholder="{{ $f['placeholder'] }}" @endisset>{{ $val }}</textarea>
        @break

    @case('select')
        <select class="{{ $class }}" id="{{ $id }}" name="{{ $name }}" @if ($locked) disabled @endif @if ($path === 'vendor_details.gst_type') data-gst-type @endif>
            <option value=""></option>
            @foreach ($page->options($f) as $code => $label)
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
            @if ($bare) <span class="sr-only">{{ $f['label'] ?? '' }}</span> @endif</label>
        @break

    @default
        <input class="{{ $class }}" type="{{ $inputType }}" id="{{ $id }}" name="{{ $name }}" maxlength="{{ $max }}"
            @if ($inputType === 'number') min="0" @isset($f['max']) max="{{ $f['max'] }}" @endisset step="any" @endif
            @if ($inputType === 'date') value="{{ substr((string) $val, 0, 10) }}" @else value="{{ $val }}" @endif
            @if ($masked) placeholder="{{ $page->raw($path) }} (leave blank to keep)" autocomplete="off" @endif
            @isset($f['aria']) aria-label="{{ $f['aria'] }}" @endisset
            @if ($locked) disabled @endif>
@endswitch

@if ($err)<p class="error" role="alert">{{ $err }}</p>@endif
@unless ($bare)
    @isset($f['help'])<p class="help">{{ $f['help'] }}</p>@endisset
</div>
@endunless
