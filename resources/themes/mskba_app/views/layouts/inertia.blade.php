@php($appearanceTheme = app(\App\Presentation\Theming\DesignThemeManager::class)->current())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-design-theme="{{ $appearanceTheme->slug }}" data-appearance-mode="{{ $appearanceTheme->mode }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="{{ $appearanceTheme->colors['bg'] }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite(['resources/themes/mskba_app/css/app.css', 'resources/themes/mskba_app/js/app.js', 'resources/themes/mskba_app/js/inertia.js'])
        <link id="mskba-appearance-css" rel="stylesheet" href="{{ app(\App\Presentation\Theming\DesignThemeManager::class)->cssUrl($appearanceTheme) }}">
        @if ($themeScript = app(\App\Presentation\Theming\DesignThemeManager::class)->jsUrl($appearanceTheme))
            <script defer src="{{ $themeScript }}"></script>
        @endif
    @inertiaHead
</head>
<body>
    @include('theme::partials.icons')
    @include('theme::partials.header')
    <main class="app-shell" id="mskba-app-content">
        @inertia
    </main>
    @include('theme::partials.auth-dialog-root')
</body>
</html>
