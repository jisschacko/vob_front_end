<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Sign in') | PartnerOne</title>
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}">
</head>
<body class="auth-body">
@if (\App\Support\EnforcementSwitches::developmentMode())
    <div class="dev-banner" role="alert">
        Development mode:
        @unless (\App\Support\EnforcementSwitches::required()) mandatory-field checks are OFF. @endunless
        @unless (\App\Support\EnforcementSwitches::documents()) document checks are OFF. @endunless
        @if (\App\Support\EnforcementSwitches::skipThirdParty()) DEMO: third-party services are skipped (bank verification is simulated, documents are stored locally). @endif
    </div>
@endif

<div class="auth-page">

    {{-- Brand panel --}}
    <section class="auth-brand">
        <svg class="auth-rings" aria-hidden="true" width="560" height="560" viewBox="0 0 560 560">
            <circle cx="280" cy="280" r="270" fill="none" stroke="#FFFFFF" stroke-width="1.5"></circle>
            <circle cx="280" cy="280" r="210" fill="none" stroke="#FFFFFF" stroke-width="1.5"></circle>
            <circle cx="280" cy="280" r="150" fill="none" stroke="#FFFFFF" stroke-width="1.5"></circle>
            <circle cx="280" cy="280" r="90" fill="none" stroke="#FFFFFF" stroke-width="1.5"></circle>
        </svg>

        <div class="auth-product">
            <div class="auth-emblem"><img src="{{ asset('images/lulu-emblem.png') }}" alt="PartnerOne logo"></div>
            <div>
                <div class="auth-product-name">PartnerOne</div>
                <div class="auth-product-by">by LGI – One Platform. One Partnership.</div>
            </div>
        </div>

        <div class="auth-pitch">
            <div class="auth-rule"></div>
            <p class="auth-tagline"><strong>PartnerOne by LGI</strong><br>One Platform. One Partnership.</p>
            <ul class="auth-points">
                <li>
                    <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#F2B636" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>
                    Self-service onboarding &amp; document management
                </li>
                <li>
                    <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#F2B636" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>
                    Real-time collaboration between Lulu teams and vendors
                </li>
            </ul>
        </div>

        <div class="auth-copy">© {{ date('Y') }} Powered by LuLu IT &amp; Innovation Team. All rights reserved.</div>
    </section>

    {{-- Form panel --}}
    <section class="auth-form-panel">
        <div class="auth-logo-row">
            <img src="{{ asset('images/lulu-logo.png') }}" alt="LuLu – Where the world comes to shop">
        </div>

        <div class="auth-center">
            <div class="auth-card">
                @if (session('status'))
                    <div class="auth-msg auth-msg-ok" role="status">{{ session('status') }}</div>
                @endif
                @yield('content')
            </div>
        </div>

        <div class="auth-footer">
            <div class="auth-secure">
                <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"></path></svg>
                Secured connection · Authorised users only
            </div>
        </div>
    </section>
</div>
@stack('scripts')
</body>
</html>
