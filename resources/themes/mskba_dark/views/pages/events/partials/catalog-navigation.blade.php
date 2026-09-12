@php
    $upcomingQuery = request()->except(['page', 'period', 'outcome', 'date_from', 'date_to']);
    $pastQuery = request()->except(['page', 'period', 'outcome', 'date_from', 'date_to']);
    $pastQuery['period'] = 'past';
    $createQuery = array_filter(['type' => $selectedType?->value]);
    $createUrl = route('events.create', $createQuery);
    $createRedirectUrl = route('events.create', $createQuery, false);
@endphp

<nav class="default-category-sidebar__nav" aria-label="Навигация мероприятий">
    <a @class(['is-active' => $period !== 'past']) href="{{ route('events.index', $upcomingQuery) }}">
        <span>Предстоящие</span>
        <i class="ti ti-calendar-up" aria-hidden="true"></i>
    </a>
    <a @class(['is-active' => $period === 'past']) href="{{ route('events.index', $pastQuery) }}">
        <span>Прошедшие</span>
        <i class="ti ti-history" aria-hidden="true"></i>
    </a>
    @auth
        <a href="{{ $createUrl }}">
            <span>Создать мероприятие</span>
            <i class="ti ti-plus" aria-hidden="true"></i>
        </a>
    @else
        <button
            class="js-handler"
            type="button"
            data-handler="modal"
            data-modal-action="open"
            data-modal-target="auth-entry-classic"
            data-auth-redirect-url="{{ $createRedirectUrl }}"
        >
            <span>Создать мероприятие</span>
            <i class="ti ti-plus" aria-hidden="true"></i>
        </button>
    @endauth
</nav>
