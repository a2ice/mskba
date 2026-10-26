@php($appearanceTheme = app(\App\Presentation\Theming\DesignThemeManager::class)->current())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-design-theme="{{ $appearanceTheme->slug }}" data-appearance-mode="{{ $appearanceTheme->mode }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="{{ $appearanceTheme->colors['bg'] }}">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="mskba-authenticated" content="{{ auth()->check() ? '1' : '0' }}">
        {{-- The Reverb app key is public; secrets never enter the HTML. --}}
        <meta name="mskba-reverb-app-key" content="{{ config('broadcasting.connections.reverb.key') }}">
        <title>{{ isset($metaTitle) && filled($metaTitle) ? $metaTitle : (isset($title) ? $title.' · MSKBA' : 'MSKBA') }}</title>
        @stack('meta')
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        @vite(['resources/themes/mskba_app/css/app.css', 'resources/themes/mskba_app/js/app.js'])
        <link id="mskba-appearance-css" rel="stylesheet" href="{{ app(\App\Presentation\Theming\DesignThemeManager::class)->cssUrl($appearanceTheme) }}">
        @if ($themeScript = app(\App\Presentation\Theming\DesignThemeManager::class)->jsUrl($appearanceTheme))
            <script defer src="{{ $themeScript }}"></script>
        @endif
        @stack('styles')
    </head>
    <body>
        @include('theme::partials.icons')
        @include('theme::partials.header')
        @include('theme::partials.context-bar')
        <main id="mskba-app" class="app-shell">
            @yield('content')
        </main>
        @include('theme::partials.footer')
        @auth
            <section class="app-message-toast-region" data-message-toast-region hidden
                     role="region" aria-label="Непрочитанные сообщения"></section>
        @endauth
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
