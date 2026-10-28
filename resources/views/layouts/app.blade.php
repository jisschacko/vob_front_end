<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Vendor Handshake') | Lulu</title>
    <link rel="stylesheet" href="{{ asset('css/vob.css') }}">
    @stack('head')
</head>
<body>
@if (\App\Support\EnforcementSwitches::developmentMode())
    <div class="dev-banner" role="alert">
        Development mode:
        @unless (\App\Support\EnforcementSwitches::required()) mandatory-field checks are OFF. @endunless
        @unless (\App\Support\EnforcementSwitches::documents()) document checks are OFF. @endunless
        @if (\App\Support\EnforcementSwitches::skipThirdParty()) DEMO: third-party services are skipped (bank verification is simulated, documents are stored locally). @endif
    </div>
@endif

<div class="layout">
    {{-- Left margin: the status tracker, shown whenever the API gave us `progress` (form and status pages). --}}
    @isset($progress)
        <x-status-tracker
            :progress="$progress"
            :vendor-name="$vendorName ?? null"
            :subtitle="$subtitle ?? null"
            :draft-saved-at="$draftSavedAt ?? null"
            :current-note="$currentNote ?? null" />
    @endisset

    <main class="content @unless (isset($progress)) content-centered @endunless">
        @if (session('admin_api.token'))
            <header class="page-header">
                <span class="page-header-user">{{ session('admin_api.user.name') }}</span>
                @if (count((array) session('admin_api.tots', [])) > 1)
                    <a class="header-link" href="{{ route('tots.index') }}">My Terms of Trade</a>
                @endif
                <form method="POST" action="{{ route('logout') }}" class="signout-form">
                    @csrf
                    <button type="submit" class="signout">Sign out</button>
                </form>
            </header>
        @endif
        <div class="main-inner">
            @if (session('status'))
                <div class="flash" role="status">{{ session('status') }}</div>
            @endif
            @yield('content')
        </div>
    </main>
</div>
@stack('scripts')
</body>
</html>
