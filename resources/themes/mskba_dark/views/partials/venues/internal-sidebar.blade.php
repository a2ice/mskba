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

        @if($bookingPolicyUrl ?? null)
            <a
                href="{{ $bookingPolicyUrl }}"
                @class(['venue-side-nav__link', 'is-active' => ($venueSidebarActive ?? null) === 'booking-policy'])
                @if(($venueSidebarActive ?? null) === 'booking-policy') aria-current="page" @endif
            >Условия аренды</a>
        @endif

        @if($commercialMembershipsUrl ?? null)
            <a
                href="{{ $commercialMembershipsUrl }}"
                @class(['venue-side-nav__link', 'is-active' => ($venueSidebarActive ?? null) === 'commercial-memberships'])
                @if(($venueSidebarActive ?? null) === 'commercial-memberships') aria-current="page" @endif
            >Коммерческие роли</a>
        @endif

        @if($venueBookingInboxUrl ?? null)
            <a href="{{ $venueBookingInboxUrl }}" class="venue-side-nav__link">Заявки на аренду</a>
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

        @if($rentalUrl ?? null)
            <a
                href="{{ $rentalUrl }}"
                class="venue-side-nav__link"
                target="_blank"
                rel="noopener noreferrer"
            >Страница аренды</a>
        @endif
    </nav>
</div>
