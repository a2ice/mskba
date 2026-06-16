@php $title = 'Площадки'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Каталог площадок, статусы и базовая модерационная сводка.',
])

@section('section-content')
    <form method="GET" action="{{ route('admin.venues') }}" class="admin-filter">
        <label class="admin-filter__field">
            <span class="admin-filter__label">Поиск</span>
            <input class="form-control" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Название, alias, адрес">
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
            <span class="admin-filter__label">Тип</span>
            <select class="form-select" name="type">
                <option value="">Все</option>
                @foreach($types as $type)
                    <option value="{{ $type->value }}" @selected(($filters['type'] ?? '') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
        </label>
        <div class="admin-filter__actions">
            <button type="submit" class="btn btn--primary btn--sm">Фильтр</button>
            <a href="{{ route('admin.venues') }}" class="btn btn--secondary btn--sm">Сброс</a>
        </div>
    </form>

    @if($venues->count() === 0)
        <div class="admin-empty">Площадки не найдены.</div>
    @else
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Alias</th>
                        <th>Статус</th>
                        <th>Тип</th>
                        <th>Создатель</th>
                        <th>Создана</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($venues as $venue)
                        <tr>
                            <td>{{ $venue->id }}</td>
                            <td>{{ $venue->name }}</td>
                            <td>{{ $venue->alias }}</td>
                            <td><span class="admin-badge">{{ $venue->status->label() }}</span></td>
                            <td>{{ $venue->type->label() }}</td>
                            <td>{{ $venue->creatorActor?->user?->username ?? '—' }}</td>
                            <td>{{ $venue->created_at?->format('d.m.Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @include('theme::partials.admin.pagination', ['paginator' => $venues])
    @endif
@endsection
