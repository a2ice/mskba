@php
    $persistent = $persistent ?? array_filter([
        'q' => filled($filters['q'] ?? null) ? $filters['q'] : null,
        'view' => request('view') === 'list' ? 'list' : null,
    ]);
@endphp

<nav class="default-category-sidebar__nav" aria-label="Навигация по участникам">
    <a @class(['is-active' => request()->routeIs('participants.players')]) href="{{ route('participants.players', $persistent) }}" @if(request()->routeIs('participants.players')) aria-current="page" @endif>
        <span>Игроки</span><i class="ti ti-ball-basketball" aria-hidden="true"></i>
    </a>
    <a @class(['is-active' => request()->routeIs('participants.coaches')]) href="{{ route('participants.coaches', $persistent) }}" @if(request()->routeIs('participants.coaches')) aria-current="page" @endif>
        <span>Тренеры</span><i class="ti ti-whistle" aria-hidden="true"></i>
    </a>
    <a @class(['is-active' => request()->routeIs('participants.index')]) href="{{ route('participants.index', $persistent) }}" @if(request()->routeIs('participants.index')) aria-current="page" @endif>
        <span>Все участники</span><i class="ti ti-users" aria-hidden="true"></i>
    </a>
    <a href="{{ route('teams.index') }}">
        <span>Команды</span><i class="ti ti-users-group" aria-hidden="true"></i>
    </a>
</nav>
