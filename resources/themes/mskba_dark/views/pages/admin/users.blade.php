@php $title = 'Пользователи'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Аккаунты, статусы подтверждения и системные роли. Онлайн: '.count($onlinePresence).'/'.$onlineSummary->totalUsers,
])

@section('section-content')
    @php
        $showDeleted = ($filters['deleted'] ?? '') === '1';
        $canManageUsers = auth()->user()?->hasSystemRole(\App\Modules\Identity\Domain\Enums\UserSystemRoleEnum::SUPERADMIN) ?? false;
    @endphp

    <form method="GET" action="{{ route('admin.users') }}" class="admin-filter">
        <label class="admin-filter__field">
            <span class="admin-filter__label">Поиск</span>
            <input class="form-control" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Логин">
        </label>
        <label class="admin-filter__field">
            <span class="admin-filter__label">Удалённые</span>
            <select class="form-select" name="deleted">
                <option value="" @selected(! $showDeleted)>Активные</option>
                <option value="1" @selected($showDeleted)>Удалённые</option>
            </select>
        </label>
        <label class="admin-filter__field">
            <span class="admin-filter__label">Статус</span>
            <select class="form-select" name="status">
                <option value="">Все</option>
                @foreach($statuses as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </label>
        <label class="admin-filter__field">
            <span class="admin-filter__label">Роль</span>
            <select class="form-select" name="role">
                <option value="">Все</option>
                @foreach($roles as $role)
                    <option value="{{ $role->value }}" @selected(($filters['role'] ?? '') === $role->value)>{{ $role->label() }}</option>
                @endforeach
            </select>
        </label>
        <div class="admin-filter__actions">
            <button type="submit" class="btn btn--primary btn--sm">Фильтр</button>
            <a href="{{ route('admin.users') }}" class="btn btn--secondary btn--sm">Сброс</a>
        </div>
    </form>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if($users->count() === 0)
        <div class="admin-empty">Пользователи не найдены.</div>
    @else
        @if($canManageUsers)
        <form
            method="POST"
            action="{{ $showDeleted ? route('admin.users.bulk-restore') : route('admin.users.bulk-delete') }}"
            data-admin-user-bulk-form
            data-confirm-message="{{ $showDeleted ? 'Вы уверены, что хотите восстановить выбранных пользователей?' : 'Вы уверены, что хотите удалить выбранных пользователей?' }}"
        >
            @csrf
            <div class="admin-table-toolbar">
                <button type="submit" class="btn {{ $showDeleted ? 'btn--success' : 'btn--danger' }} btn--sm" data-admin-user-bulk-submit disabled>
                    {{ $showDeleted ? 'Восстановить' : 'Удалить' }}
                </button>
            </div>
        @endif

        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        @if($canManageUsers)
                            <th class="admin-table__check-cell">
                                <input type="checkbox" aria-label="Выбрать всех пользователей" data-admin-user-select-all>
                            </th>
                        @endif
                        <th>ID</th>
                        <th>Логин</th>
                        <th class="admin-table__presence-cell">Онлайн</th>
                        <th>Статус</th>
                        <th>Системная роль</th>
                        <th>Операционные права</th>
                        <th>Имя</th>
                        <th>Дата регистрации</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $user)
                        <tr>
                            @if($canManageUsers)
                                <td class="admin-table__check-cell">
                                    <input
                                        type="checkbox"
                                        name="user_ids[]"
                                        value="{{ $user->id }}"
                                        aria-label="Выбрать пользователя {{ $user->username }}"
                                        data-admin-user-select
                                        @disabled($user->is(auth()->user()))
                                    >
                                </td>
                            @endif
                            <td>{{ $user->id }}</td>
                            <td>{{ $user->username }}</td>
                            <td class="admin-table__presence-cell">
                                @php
                                    $lastSeenTimestamp = $onlinePresence[$user->id] ?? null;
                                    $lastSeen = $lastSeenTimestamp
                                        ? \Illuminate\Support\Carbon::createFromTimestamp($lastSeenTimestamp, config('app.timezone'))
                                        : null;
                                    $presenceTooltip = $lastSeen
                                        ? 'Онлайн. Последняя активность: '.$lastSeen->format('d.m.Y H:i:s')
                                        : 'Не в сети';
                                @endphp
                                <span
                                    class="admin-user-presence {{ $lastSeen ? 'admin-user-presence--online' : 'admin-user-presence--offline' }}"
                                    title="{{ $presenceTooltip }}"
                                    data-tooltip-variant="title"
                                    aria-label="{{ $presenceTooltip }}"
                                ><i aria-hidden="true"></i></span>
                            </td>
                            <td>
                                @if($canManageUsers && ! $showDeleted && ! $user->is(auth()->user()))
                                    <button
                                        type="button"
                                        class="admin-badge admin-badge--button"
                                        data-admin-action-modal-open="user-status-{{ $user->id }}"
                                    >{{ $user->status->label() }}</button>
                                @else
                                    <span class="admin-badge">{{ $showDeleted ? 'Удалён' : $user->status->label() }}</span>
                                @endif
                            </td>
                            <td>{{ $user->system_role->label() }}</td>
                            <td>
                                @php
                                    $permissionSnapshot = $user->operationalPermissions
                                        ->keyBy(fn ($entry) => $entry->permission->value);
                                    $allowedPermissionCount = collect($operationalPermissions)
                                        ->filter(fn ($permission) => $permissionSnapshot->get($permission->value)?->is_allowed ?? true)
                                        ->count();
                                    $canManageOperationalPermissions = ! $showDeleted
                                        && auth()->user()?->can('manage-user-operational-permissions', $user);
                                @endphp
                                @if($canManageOperationalPermissions)
                                    <button
                                        type="button"
                                        class="admin-badge admin-badge--button"
                                        data-admin-action-modal-open="user-permissions-{{ $user->id }}"
                                    >{{ $allowedPermissionCount }}/{{ count($operationalPermissions) }}</button>
                                @else
                                    <span class="admin-badge">{{ $allowedPermissionCount }}/{{ count($operationalPermissions) }}</span>
                                @endif
                            </td>
                            <td>{{ trim(($user->profile?->first_name ?? '').' '.($user->profile?->last_name ?? '')) ?: '—' }}</td>
                            <td>{{ $user->created_at?->format('d.m.Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($canManageUsers)
        </form>
        @endif

        @if($canManageUsers && ! $showDeleted)
            @foreach($users->reject(fn ($user) => $user->is(auth()->user())) as $user)
                <div class="admin-action-modal" data-admin-action-modal="user-status-{{ $user->id }}" hidden>
                    <div class="admin-action-modal__backdrop" data-admin-action-modal-close></div>
                    <section class="admin-action-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="user-status-title-{{ $user->id }}">
                        <button type="button" class="admin-action-modal__close" data-admin-action-modal-close aria-label="Закрыть"></button>
                        <p class="admin-kicker">Статус пользователя</p>
                        <h3 id="user-status-title-{{ $user->id }}" class="admin-action-modal__title">{{ $user->username ?: 'Пользователь #'.$user->id }}</h3>
                        <p class="admin-action-modal__description">Сейчас: <strong>{{ $user->status->label() }}</strong></p>

                        <div class="admin-moderation-actions admin-moderation-actions--single-row">
                            @foreach($statuses as $status)
                                @continue($status === $user->status)
                                <form method="POST" action="{{ route('admin.users.status.update', $user) }}" data-admin-confirm="Изменить статус пользователя на «{{ $status->label() }}»?">
                                    @csrf
                                    <input type="hidden" name="status" value="{{ $status->value }}">
                                    <button type="submit" class="btn {{ $status === \App\Modules\Identity\Domain\Enums\UserStatusEnum::BLOCKED ? 'btn--danger' : 'btn--secondary' }} btn--sm">
                                        {{ $status->label() }}
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    </section>
                </div>
            @endforeach
        @endif

        @if(! $showDeleted)
            @foreach($users->filter(fn ($user) => auth()->user()?->can('manage-user-operational-permissions', $user)) as $user)
                @php
                    $permissionSnapshot = $user->operationalPermissions
                        ->keyBy(fn ($entry) => $entry->permission->value);
                @endphp
                <div class="admin-action-modal" data-admin-action-modal="user-permissions-{{ $user->id }}" hidden>
                    <div class="admin-action-modal__backdrop" data-admin-action-modal-close></div>
                    <section class="admin-action-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="user-permissions-title-{{ $user->id }}">
                        <button type="button" class="admin-action-modal__close" data-admin-action-modal-close aria-label="Закрыть"></button>
                        <p class="admin-kicker">Операционные права</p>
                        <h3 id="user-permissions-title-{{ $user->id }}" class="admin-action-modal__title">{{ $user->username ?: 'Пользователь #'.$user->id }}</h3>
                        <p class="admin-action-modal__description">Новые права по умолчанию включены. Здесь можно изменить персональный набор пользователя.</p>

                        <form method="POST" action="{{ route('admin.users.operational-permissions.update', $user) }}">
                            @csrf
                            <div class="admin-permission-list">
                                @foreach($operationalPermissions as $permission)
                                    <label class="admin-permission-option">
                                        <input
                                            type="checkbox"
                                            name="permissions[]"
                                            value="{{ $permission->value }}"
                                            @checked($permissionSnapshot->get($permission->value)?->is_allowed ?? true)
                                        >
                                        <span>
                                            <strong>{{ $permission->label() }}</strong>
                                            <small>{{ $permission->value }}</small>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <div class="admin-action-modal__actions">
                                <button type="submit" class="btn btn--primary btn--sm">Сохранить</button>
                                <button type="button" class="btn btn--secondary btn--sm" data-admin-action-modal-close>Отмена</button>
                            </div>
                        </form>
                    </section>
                </div>
            @endforeach
        @endif

        @include('theme::partials.admin.pagination', ['paginator' => $users])
    @endif
@endsection
