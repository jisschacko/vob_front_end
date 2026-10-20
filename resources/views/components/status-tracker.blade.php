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
<aside class="tracker" aria-label="Onboarding progress">
    <div class="logo"><img src="{{ asset('images/lulu-hypermarket.jpg') }}" alt="Lulu"></div>
    @if ($vendorName)
        <h2>{{ $vendorName }}</h2>
    @endif
    <p class="sub">
        {{ $subtitle }}
        @if ($when($draftSavedAt, 'd-M-Y, h:i A'))
            <br>Draft saved {{ $when($draftSavedAt, 'd-M-Y, h:i A') }}
        @endif
    </p>

    <ol class="steps">
        @foreach ($steps as $i => $step)
            @php($state = in_array($step['state'] ?? '', ['done', 'current', 'pending'], true) ? $step['state'] : 'pending')
            <li class="step {{ $state }}" @if ($state === 'current') aria-current="step" @endif>
                <span class="dot" data-n="{{ $i + 1 }}"></span>
                <span class="label">{{ config('vob.tracker_labels.'.($step['key'] ?? ''), $step['label'] ?? $step['key'] ?? '') }}
                    @if ($state === 'current' && $currentNote)
                        <small>{{ $currentNote }}</small>
                    @elseif ($state === 'done' && $when($step['entered_at'] ?? null, 'd-M-Y'))
                        <small>{{ $when($step['entered_at'], 'd-M-Y') }}</small>
                    @endif
                </span>
            </li>
        @endforeach
    </ol>
</aside>
