@php
    $theme = app(\App\Presentation\Theming\ThemeResolver::class);
    $pageTitle = isset($title) ? $title.' · '.config('app.name', 'MSKBA') : config('app.name', 'MSKBA');

    $routeClass = 'page-'.str_replace('.', '-', Route::currentRouteName() ?? 'default');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#10120f">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $pageTitle }}</title>
        @vite($theme->viteInputs())
    </head>
    <body class="theme-shell {{ $routeClass }}">
        <div class="site-frame">
            @include('theme::partials.header')

            <main class="site-content">                
                @yield('content')
            </main>

            @include('theme::partials.footer')
        </div>

        @include('theme::partials.modal')
    </body>
</html>
