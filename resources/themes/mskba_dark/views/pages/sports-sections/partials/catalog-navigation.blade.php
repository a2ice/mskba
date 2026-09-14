@php
    $persistent = request()->except(['page', 'accepts_requests', 'recruiting']);
    $allQuery = $persistent;
    $applicationsQuery = array_merge($persistent, ['accepts_requests' => 1]);
    $recruitingQuery = array_merge($persistent, ['recruiting' => 1]);
    $isAccepting = (bool) ($filters['accepts_requests'] ?? false);
    $isRecruiting = (bool) ($filters['recruiting'] ?? false);
@endphp

<nav class="default-category-sidebar__nav" aria-label="Навигация по спортивным секциям">
    <a @class(['is-active' => ! $isAccepting && ! $isRecruiting]) href="{{ route('sports-sections.index', $allQuery) }}" @if(! $isAccepting && ! $isRecruiting) aria-current="page" @endif>
        <span>Все секции</span><i class="ti ti-ball-basketball" aria-hidden="true"></i>
    </a>
    <a @class(['is-active' => $isAccepting && ! $isRecruiting]) href="{{ route('sports-sections.index', $applicationsQuery) }}" @if($isAccepting && ! $isRecruiting) aria-current="page" @endif>
        <span>Принимают заявки</span><i class="ti ti-clipboard-check" aria-hidden="true"></i>
    </a>
    <a @class(['is-active' => $isRecruiting]) href="{{ route('sports-sections.index', $recruitingQuery) }}" @if($isRecruiting) aria-current="page" @endif>
        <span>Идёт набор</span><i class="ti ti-user-plus" aria-hidden="true"></i>
    </a>

    @auth
        <a href="{{ route('account.sports-sections.index') }}"><span>Мои секции</span><i class="ti ti-user-shield" aria-hidden="true"></i></a>
    @endauth
    <a href="{{ route('account.sports-sections.create') }}"><span>Создать секцию</span><i class="ti ti-plus" aria-hidden="true"></i></a>
</nav>
