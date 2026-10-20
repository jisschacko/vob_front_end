<div class="card">
    <div class="card-header">{{ $card['title'] }}</div>
    <div class="card-body">
        @foreach ($card['blocks'] as $b)
            @include('tot._block', ['b' => $b])
        @endforeach
    </div>
</div>
