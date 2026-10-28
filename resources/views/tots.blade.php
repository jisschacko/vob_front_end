@extends('layouts.app')

@section('title', 'My Terms of Trade')

@section('content')
    <div class="card tot-list">
        <h1>My Terms of Trade</h1>

        @if ($tots === [])
            <p>No Terms of Trade yet. Please contact your Lulu buyer.</p>
        @else
            <p class="muted">Choose the Terms of Trade you want to open.</p>
            @error('index') <p class="error" role="alert">{{ $message }}</p> @enderror

            <table class="tot-table">
                <thead>
                    <tr><th>Financial year</th><th>Type</th><th>Status</th><th>Editable</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($tots as $i => $row)
                        <tr @if (($row['id'] ?? null) === $current) class="current-row" @endif>
                            <td>{{ $row['financial_year'] ?? '' }}</td>
                            <td>{{ ($row['type'] ?? '') === 'renew' ? 'Renewal' : 'New' }}</td>
                            <td>{{ $row['status_label'] ?? $row['status'] ?? '' }}</td>
                            <td>{{ ! empty($row['editable']) ? 'Yes' : 'Read only' }}</td>
                            <td>
                                <form method="POST" action="{{ route('tots.select') }}" class="row-form">
                                    @csrf
                                    <input type="hidden" name="index" value="{{ $i }}">
                                    <button type="submit" class="row-open">{{ ! empty($row['editable']) ? 'Open' : 'View' }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
