<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · MSKBA</title>
    @vite('resources/themes/mskba_dark/css/app.css')
</head>
<body>
    <main class="container py-4 admin-section">
        @if (auth()->user()->canAccessPanel(\Filament\Facades\Filament::getPanel('backoffice')))
            <a href="{{ route('filament.backoffice.pages.dashboard') }}" class="btn btn--secondary btn--sm mb-3">В панель управления</a>
        @endif
        <header class="section-content-heading mb-4">
            <div class="section-content-heading__row">
                <h1 class="layout-content-title">{{ $title }}</h1>
                <div class="section-content-heading__action">
                    @yield('section-heading-action')
                </div>
            </div>
            <p class="section-sidebar-layout__subtitle">{{ $subtitle }}</p>
        </header>
        @yield('section-content')
    </main>
</body>
</html>
