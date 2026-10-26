@php $flagged = isset($card['id']) && $page->isFlagged($card['id']) @endphp
<div class="card" @isset($card['id']) id="{{ $card['id'] }}" @endisset @if ($flagged) data-flagged @endif>
    <div class="card-header">{{ $card['title'] }}@if ($flagged) <span class="badge badge-flag">Comment added</span>@endif</div>
    <div class="card-body">
        @foreach ($card['blocks'] as $b)
            @include('tot._block', ['b' => $b])
        @endforeach
    </div>
</div>
