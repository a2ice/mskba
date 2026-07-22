<div class="section-sidebar-block">
    <h2 class="section-sidebar-block__title">Управление</h2>

    <nav class="venue-side-nav" aria-label="Внутренняя навигация площадки">
        <a
            href="{{ route('account.venues.show', $venue->routeIdentifier()) }}"
            @class(['venue-side-nav__link', 'is-active' => ($venueSidebarActive ?? null) === 'show'])
            @if(($venueSidebarActive ?? null) === 'show') aria-current="page" @endif
        >Обзор</a>

        @if($venue->allowsDetailsEditing())
            <a
                href="{{ route('account.venues.edit', $venue->routeIdentifier()) }}"
                @class(['venue-side-nav__link', 'is-active' => ($venueSidebarActive ?? null) === 'edit'])
                @if(($venueSidebarActive ?? null) === 'edit') aria-current="page" @endif
            >Редактировать</a>
        @endif

        @if($venue->allowsOperationalChanges())
            <a
                href="{{ route('account.venues.schedule.edit', $venue->routeIdentifier()) }}"
                @class(['venue-side-nav__link', 'is-active' => ($venueSidebarActive ?? null) === 'schedule'])
                @if(($venueSidebarActive ?? null) === 'schedule') aria-current="page" @endif
            >Расписание</a>
        @endif

        <a
            href="{{ route('account.venues.status', $venue->routeIdentifier()) }}"
            @class(['venue-side-nav__link', 'is-active' => ($venueSidebarActive ?? null) === 'status'])
            @if(($venueSidebarActive ?? null) === 'status') aria-current="page" @endif
        >Модерация</a>

        <a
            href="{{ route('venues.show', $venue->routeIdentifier()) }}"
            class="venue-side-nav__link"
            target="_blank"
            rel="noopener noreferrer"
        >Просмотр</a>
    </nav>
</div>
