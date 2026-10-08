<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#090a0b">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite(['resources/themes/mskba_app/css/app.css', 'resources/themes/mskba_app/js/app.js', 'resources/themes/mskba_app/js/inertia.js'])
    @inertiaHead
</head>
<body>
    @include('theme::partials.icons')
    @include('theme::partials.header')
    <main class="app-shell container" id="mskba-app-content">
        @inertia
    </main>
    @include('theme::partials.auth-dialog-root')
</body>
</html>
