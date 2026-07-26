@php
    $theme = app(\App\Presentation\Theming\ThemeResolver::class);
    $pageTitle = isset($title) ? $title.' · '.config('app.name', 'MSKBA') : config('app.name', 'MSKBA');

    $routeClass = 'page-'.str_replace('.', '-', Route::currentRouteName() ?? 'default');

    $isTelegramMiniApp = ($telegramMiniApp ?? false) === true
        || session()->get('telegram_mini_app_context') === true;
    $shouldBootstrapTelegramAuth = ($telegramAuthBootstrap ?? false) === true;
    $isMainPage = Route::currentRouteName() === 'welcome'
        || Route::currentRouteName() === 'integrations.telegram.main';

    $routeClass .= $isMainPage ? ' main' : '';
    $routeClass .= $isTelegramMiniApp ? ' telegram-mini-app' : '';

    $user = auth()->user();
    $user?->loadMissing('profile.activeAvatar');
    $headerTelegramAccount = $user
        ? \App\Modules\Telegram\Domain\Models\TelegramAccount::query()->where('user_id', $user->id)->first()
        : null;

    $userLoginLabel = $user ? $user->username : 'Войти';
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#10120f">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" href="{{ asset('favicon-32x32.png') }}" type="image/png">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
        <link rel="manifest" href="{{ asset('site.webmanifest') }}">
        <meta name="yandex-verification" content="5e74a0d5140e0b49" />
        <title>{{ $pageTitle }}</title>
        @include('partials.analytics.yandex-metrika')
        @if($isTelegramMiniApp)
            <script async src="https://telegram.org/js/telegram-web-app.js" data-telegram-sdk></script>
        @endif
        @vite($theme->viteInputs())
        @yield('styles')
    </head>
    <body
        class="theme-shell {{ $routeClass }}"
        style="--site-body-bottom-bg: url('{{ asset('images/bg-indoor.png') }}');"
        @if($isTelegramMiniApp)
            data-telegram-mini-app
            data-account-url="{{ route('account') }}"
            @if($shouldBootstrapTelegramAuth)
                data-telegram-auth-bootstrap
                data-telegram-auth-url="{{ route('integrations.telegram.auth') }}"
            @endif
        @endif
        data-site-summary-url="{{ route('site-summary.heartbeat') }}"
        data-site-summary-heartbeat-interval="{{ max(30, (int) config('site_summary.heartbeat_interval_seconds', 45)) }}"
    >
        @include('partials.analytics.yandex-metrika-noscript')

        <div class="site-frame">
            @include('theme::partials.header')

            <main class="site-content">
                @yield('content')
            </main>

            @include('theme::partials.mobile-primary-bar')

            <footer class="site-footer">
                @include('theme::partials.footer')
            </footer>
        </div>

        @include('theme::partials.modal')
    </body>
</html>
