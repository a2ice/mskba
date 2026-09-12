@php
    $persistent = request()->except(['page', 'hiring']);
    $allQuery = $persistent;
    $hiringQuery = array_merge($persistent, ['hiring' => 1]);
    $isHiring = (bool) ($filters['hiring'] ?? false);
@endphp

<nav class="default-category-sidebar__nav" aria-label="Навигация по командам">
    <a @class(['is-active' => ! $isHiring]) href="{{ route('teams.index', $allQuery) }}" @if(! $isHiring) aria-current="page" @endif>
        <span>Все команды</span><i class="ti ti-users-group" aria-hidden="true"></i>
    </a>
    <a @class(['is-active' => $isHiring]) href="{{ route('teams.index', $hiringQuery) }}" @if($isHiring) aria-current="page" @endif>
        <span>Идёт набор</span><i class="ti ti-user-plus" aria-hidden="true"></i>
    </a>

    @auth
        <a href="{{ route('account.teams') }}">
            <span>Мои команды</span><i class="ti ti-user-shield" aria-hidden="true"></i>
        </a>
        @can('team-create')
            <a href="{{ route('teams.create') }}">
                <span>Создать команду</span><i class="ti ti-plus" aria-hidden="true"></i>
            </a>
        @endcan
    @else
        <button
            type="button"
            class="js-handler"
            data-handler="modal"
            data-modal-action="open"
            data-modal-target="auth-entry-classic"
            data-auth-redirect-url="{{ route('teams.create', [], false) }}"
        >
            <span>Создать команду</span><i class="ti ti-plus" aria-hidden="true"></i>
        </button>
    @endauth
</nav>
