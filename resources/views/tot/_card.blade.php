@php
    $flagged = isset($card['id']) && $page->isFlagged($card['id']);
    $skuButton = ($card['id'] ?? null) === 'margin_rebate' && ! $page->isLocked('margins'); // vendor can add articles to the margin table
@endphp
<div class="card" @isset($card['id']) id="{{ $card['id'] }}" @endisset @if ($flagged) data-flagged @endif>
    @if ($skuButton)
        <div class="card-header has-action">
            <span>{{ $card['title'] }}@if ($flagged) <span class="badge badge-flag">Comment added</span>@endif</span>
            <button type="button" class="sku-open" data-sku-open>SKU Data</button>
        </div>
    @else
        <div class="card-header">{{ $card['title'] }}@if ($flagged) <span class="badge badge-flag">Comment added</span>@endif</div>
    @endif
    <div class="card-body">
        @foreach ($card['blocks'] as $b)
            @include('tot._block', ['b' => $b])
        @endforeach
        @if ($skuButton)
            @include('tot._sku_dialog')
        @endif
    </div>
</div>
