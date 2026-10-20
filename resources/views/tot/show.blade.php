@extends('layouts.app')

@section('title', 'Terms of Trade')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/tot.css') }}">
    <script src="{{ asset('js/tot-form.js') }}" defer></script>
@endpush

@section('content')
<div class="tot">
    <div class="topbar">
        <img class="logo" src="{{ asset('images/lulu-hypermarket.jpg') }}" alt="LuLu Group International">
        <div>
            <h1>Business Development Agreement &mdash; Terms of Trade (TOT)</h1>
            <p>Central Buying Division: Lulu India &nbsp;&middot;&nbsp; Lulu International Shopping Malls Private Limited</p>
        </div>
    </div>

    <p class="muted"><strong>Status:</strong> {{ $tot['status_label'] ?? $tot['status'] ?? '' }}</p>

    @if (! $page->editable())
        <div class="status-card" role="status">
            <strong>{{ ! empty($tot['submitted_at']) ? 'Submitted' : 'Not editable' }}</strong>
            @if (! empty($tot['submitted_at']))
                on {{ \Illuminate\Support\Carbon::parse($tot['submitted_at'])->setTimezone(config('vob.display_timezone'))->format('d-M-Y, h:i A') }}.
            @endif
            It is now <strong>{{ strtolower($tot['status_label'] ?? 'under review') }}</strong> and can no longer be edited. Follow its progress on the left.
            You will be asked to update it only if a reviewer sends it back.
        </div>
    @elseif ($page->isResubmission())
        <div class="status-card status-card-warn" role="status">
            A reviewer sent this Terms of Trade back to you. Read the comments below, update the form and press <strong>Resubmit</strong>.
        </div>
    @endif

    @if (session('submit_warnings'))
        <div class="dev-note dev-note-block" role="status">
            <strong>Submitted. Development mode only:</strong> these items were still incomplete, and the checks are switched off.
            <ul>@foreach (session('submit_warnings') as $warning)<li>{{ $warning }}</li>@endforeach</ul>
        </div>
    @endif

    @foreach ($page->switchMismatches() as $mismatch)
        <div class="dev-note">{{ $mismatch }}</div>
    @endforeach

    @if ($page->reviewerComments() !== [])
        <div class="card">
            <div class="card-header">Reviewer comments</div>
            <div class="card-body">
                @foreach ($page->reviewerComments() as $comment)
                    <blockquote>{{ $comment['comments'] ?? '' }}<footer>{{ $comment['by'] ?? '' }}{{ ! empty($comment['at']) ? ', '.$comment['at'] : '' }}</footer></blockquote>
                @endforeach
            </div>
        </div>
    @endif

    @if ($errors->any())
        <div class="error-summary" role="alert">
            @php $special = collect(['form', 'document', 'bank_verify'])->first(fn ($k) => $errors->has($k)) @endphp
            @if ($errors->has('submit'))
                <strong>{{ $errors->first('submit') }} Your draft was saved.</strong>
                <ul>@foreach ($errors->getMessages() as $key => $messages)@if ($key !== 'submit')<li>{{ $messages[0] }}</li>@endif @endforeach</ul>
            @else
                <strong>{{ $special ? $errors->first($special) : 'Some values need correcting. Nothing was saved.' }}</strong>
                @unless ($special)
                    <ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
                @endunless
            @endif
        </div>
    @endif

    <form id="tot-form" method="POST" action="{{ route('tot.draft') }}" data-autosave="{{ route('tot.autosave') }}" novalidate>
        @csrf
        @foreach ($page->maskedPaths() as $maskedPath)
            <input type="hidden" name="masked[]" value="{{ $maskedPath }}">
        @endforeach

        @foreach (\App\Services\Tot\TotLayout::cards() as $item)
            @if (isset($item['divider']))
                <div class="page-divider">{{ $item['divider'] }}</div>
            @elseif (isset($item['view']))
                @include($item['view'])
            @else
                @include('tot._card', ['card' => $item])
            @endif
        @endforeach

        <div class="actionbar">
            @if ($page->editable())
                <button type="submit" class="btn">Save Draft</button>
                <button type="submit" class="btn btn-secondary" formaction="{{ route('tot.submit') }}" data-submit
                        data-confirm="{{ $page->isResubmission() ? 'Resubmit this Terms of Trade for review?' : 'Submit this Terms of Trade for review? You will not be able to edit it afterwards unless a reviewer sends it back.' }}">{{ $page->isResubmission() ? 'Resubmit' : 'Submit' }}</button>
            @endif
            <span id="autosave-status" class="muted" aria-live="polite">
                @if ($draftSavedAt) Draft saved {{ \Illuminate\Support\Carbon::parse($draftSavedAt)->setTimezone(config('vob.display_timezone'))->format('d-M-Y, h:i A') }} @endif
            </span>
        </div>
    </form>

    {{-- Document forms live outside the main form (forms cannot nest); the file inputs above point at them with form="..." --}}
    @if ($page->editable())
        <form id="bank-verify" method="POST" action="{{ route('bank.create') }}">@csrf</form>
        @foreach (array_keys(\App\Services\Tot\TotLayout::documents()) as $type)
            <form id="doc-up-{{ $type }}" method="POST" action="{{ route('tot.documents.store') }}" enctype="multipart/form-data">
                @csrf <input type="hidden" name="type" value="{{ $type }}">
            </form>
            <form id="doc-rm-{{ $type }}" method="POST" action="{{ route('tot.documents.destroy') }}">
                @csrf <input type="hidden" name="type" value="{{ $type }}">
            </form>
        @endforeach
    @endif

    <p class="footer-note">Lulu International Shopping Malls Private Limited &nbsp;&middot;&nbsp; Business Development Agreement / Terms of Trade (TOT)</p>
</div>
@endsection
