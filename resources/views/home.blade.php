@extends('layouts.app')

@section('title', 'Terms of Trade')

@section('content')
    <div class="card">
        @if ($tot === null)
            <h1>Welcome</h1>
            <p>No Terms of Trade is linked to this sign-in. Please open the link in your invitation email.</p>
        @else
            <h1>Terms of Trade {{ $tot['financial_year'] ?? '' }}</h1>
            <p><strong>Status:</strong> {{ $tot['status_label'] ?? $tot['status'] ?? '' }}</p>

            @if (! empty($reviewerComments))
                <h2>Reviewer comments</h2>
                @foreach ($reviewerComments as $comment)
                    <blockquote>
                        {{ $comment['comments'] ?? '' }}
                        <footer>{{ $comment['by'] ?? '' }}</footer>
                    </blockquote>
                @endforeach
            @endif

            <p class="muted">The Terms of Trade form will appear here.</p>
        @endif

        <form method="POST" action="{{ route('logout') }}" class="inline-form">
            @csrf
            <button type="submit" class="link">Sign out</button>
        </form>
    </div>
@endsection
