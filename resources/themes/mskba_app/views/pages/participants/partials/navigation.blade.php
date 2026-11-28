<nav class="app-category__nav" aria-label="Разделы участников">
    @foreach([
        ['participants.index', 'Все участники'],
        ['participants.players', 'Игроки'],
        ['participants.coaches', 'Тренеры'],
        ['teams.index', 'Команды'],
    ] as [$route, $label])
        <a href="{{ route($route) }}" @class(['is-active' => ($activeSection ?? null) === $route || request()->routeIs($route)]) @if(request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
