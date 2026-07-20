<div class="section-sidebar-block">
    <h2 class="section-sidebar-block__title">Управление</h2>

    <nav class="venue-side-nav" aria-label="Внутренняя навигация площадки">
        <a
            href="{{ route('venues.show', $venue->routeIdentifier()) }}"
            @class(['venue-side-nav__link', 'is-active' => ($venueSidebarActive ?? null) === 'show'])
            @if(($venueSidebarActive ?? null) === 'show') aria-current="page" @endif
        >К просмотру</a>

        @if($venue->allowsDetailsEditing())
            <a
                href="{{ route('venues.edit', $venue->routeIdentifier()) }}"
                @class(['venue-side-nav__link', 'is-active' => ($venueSidebarActive ?? null) === 'edit'])
                @if(($venueSidebarActive ?? null) === 'edit') aria-current="page" @endif
            >Редактировать</a>
        @endif

        <a
            href="{{ route('venues.status', $venue->routeIdentifier()) }}"
            @class(['venue-side-nav__link', 'is-active' => ($venueSidebarActive ?? null) === 'status'])
            @if(($venueSidebarActive ?? null) === 'status') aria-current="page" @endif
        >Статус</a>
    </nav>
</div>
