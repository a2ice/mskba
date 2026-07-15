@php
    $theme = app(\App\Presentation\Theming\ThemeResolver::class);
    $pageTitle = isset($title) ? $title.' · '.config('app.name', 'MSKBA') : config('app.name', 'MSKBA');
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
        <title>{{ $pageTitle }}</title>
        <script src="https://telegram.org/js/telegram-web-app.js"></script>
        @vite($theme->viteInputs())
        @yield('styles')
    </head>
    <body class="telegram-app-shell">
        <main class="telegram-app-content">
            @yield('content')
        </main>
    </body>
</html>
