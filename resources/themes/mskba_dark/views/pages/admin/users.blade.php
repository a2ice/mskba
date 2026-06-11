@php $title = 'Пользователи'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Аккаунты, статусы подтверждения и системные роли.',
])

@section('section-content')
    <form method="GET" action="{{ route('admin.users') }}" class="admin-filter">
        <label class="admin-filter__field">
            <span class="admin-filter__label">Поиск</span>
            <input class="form-control" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Логин">
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

    @if($users->count() === 0)
        <div class="admin-empty">Пользователи не найдены.</div>
    @else
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Логин</th>
                        <th>Статус</th>
                        <th>Системная роль</th>
                        <th>Имя</th>
                        <th>Дата регистрации</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $user)
                        <tr>
                            <td>{{ $user->id }}</td>
                            <td>{{ $user->username }}</td>
                            <td><span class="admin-badge">{{ $user->status->label() }}</span></td>
                            <td>{{ $user->system_role->label() }}</td>
                            <td>{{ trim(($user->profile?->first_name ?? '').' '.($user->profile?->last_name ?? '')) ?: '—' }}</td>
                            <td>{{ $user->created_at?->format('d.m.Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @include('theme::partials.admin.pagination', ['paginator' => $users])
    @endif
@endsection
