<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#090a0b">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ isset($title) ? $title.' · MSKBA' : 'MSKBA' }}</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        @vite(['resources/themes/mskba_app/css/app.css', 'resources/themes/mskba_app/js/app.js'])
        @stack('styles')
    </head>
    <body>
        @include('theme::partials.icons')
        @include('theme::partials.header')
        @include('theme::partials.context-bar')
        <main id="mskba-app" class="app-shell">
            @yield('content')
        </main>
        @include('theme::partials.auth-dialog-root')
        @auth
            @if (app(\App\Modules\Identity\Application\Services\PersonalDataDistributionConsentService::class)->requiresSetup(auth()->user()))
                <div data-onboarding-pending
                     data-onboarding-url="{{ route('account.privacy.distribution') }}"
                     data-onboarding-save="{{ route('account.privacy.distribution.update') }}"
                     data-account-url="{{ route('account') }}"
                     data-logout-url="{{ route('auth.logout') }}"
                     data-auto-show="{{ (request()->is('account', 'account/*') && ! request()->routeIs('account.privacy.distribution')) ? '1' : '0' }}"
                     data-force-show="{{ (session()->has('onboarding_required') && ! request()->routeIs('account.privacy.distribution')) ? '1' : '0' }}">
                </div>
            @endif
        @endauth
        @stack('scripts')
    </body>
</html>
