@props([
    'progress' => [],          // API `progress`: { current, steps: [{ key, label, state: done|current|pending, entered_at }] }
    'vendorName' => null,
    'subtitle' => null,        // e.g. "Terms of Trade 2026-27"
    'draftSavedAt' => null,    // API `draft_saved_at` (ISO 8601)
    'currentNote' => null,     // small text under the current step, e.g. "Action needed: see reviewer comments"
])
@php
    $tz = config('vob.display_timezone');
    $when = fn ($iso, $format) => $iso ? \Illuminate\Support\Carbon::parse($iso)->setTimezone($tz)->format($format) : null;
    $steps = $progress['steps'] ?? [];
@endphp
{{-- Left menu: logo on top, then user, Terms of Trade, draft time. Opens while the pointer is over it (public/js/menu.js). --}}
<div class="status-backdrop" aria-hidden="true"></div>
<div class="menu-col" data-menu>
    <div class="menu-logo">
        <img class="logo-full" src="{{ asset('images/lulu-hypermarket-transparent.png') }}" alt="Lulu">
        <img class="logo-mini" src="{{ asset('images/lulu-emblem.png') }}" alt="" aria-hidden="true">
    </div>
    <aside class="tracker" aria-label="Terms of Trade">
        <div id="menu-panel" class="menu-panel">
            <div class="tracker-float">
            @if ($vendorName)
                <h2>{{ $vendorName }}</h2>
            @endif
            <p class="sub">
                {{ $subtitle }}
                @if ($when($draftSavedAt, 'd-M-Y, h:i A'))
                    <br>Draft saved {{ $when($draftSavedAt, 'd-M-Y, h:i A') }}
                @endif
            </p>
            </div>
        </div>
    </aside>
</div>
{{-- Band in the menu colour above the status bar. --}}
<div class="top-band" aria-hidden="true"></div>
{{-- Status: floats across the top of the page while it scrolls. --}}
<nav class="status-bar" aria-label="Onboarding progress">
    <div class="status-row">
        <h3 class="steps-title">Status</h3>
        <div class="status-actions">{{ $slot }}</div>
    </div>
    <ol class="steps">
        @foreach ($steps as $i => $step)
            @php($state = in_array($step['state'] ?? '', ['done', 'current', 'pending'], true) ? $step['state'] : 'pending')
            <li class="step {{ $state }}" @if ($state === 'current') aria-current="step" @endif>
                <span class="dot" data-n="{{ $i + 1 }}"></span>
                <span class="label">{{ config('vob.tracker_labels.'.($step['key'] ?? ''), $step['label'] ?? $step['key'] ?? '') }}
                    @if ($state === 'done' && $when($step['entered_at'] ?? null, 'd-M-Y'))
                        <small>{{ $when($step['entered_at'], 'd-M-Y') }}</small>
                    @endif
                </span>
            </li>
        @endforeach
    </ol>
</nav>
