@php $title = 'Мероприятия'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Игры, тренировки, игровые тренировки и турниры.',
])

@section('section-content')
    <form method="GET" action="{{ route('admin.events') }}" class="admin-filter">
        <label class="admin-filter__field">
            <span class="admin-filter__label">Поиск</span>
            <input class="form-control" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Название мероприятия">
        </label>
        <label class="admin-filter__field">
            <span class="admin-filter__label">Статус</span>
            <select class="form-select" name="status">
                <option value="">Все</option>
                @foreach($statuses as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>@endforeach
            </select>
        </label>
        <label class="admin-filter__field">
            <span class="admin-filter__label">Тип</span>
            <select class="form-select" name="type">
                <option value="">Все</option>
                @foreach($types as $type)<option value="{{ $type->value }}" @selected(($filters['type'] ?? '') === $type->value)>{{ $type->label() }}</option>@endforeach
            </select>
        </label>
        <div class="admin-filter__actions">
            <button type="submit" class="btn btn--primary btn--sm">Фильтр</button>
            <a href="{{ route('admin.events') }}" class="btn btn--secondary btn--sm">Сброс</a>
        </div>
    </form>

    @if($events->isEmpty())
        <div class="admin-empty">Мероприятий пока нет.</div>
    @else
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead><tr><th>ID</th><th>Название</th><th>Тип</th><th>Статус</th><th>Площадка</th><th>Начало</th><th>Участники</th><th></th></tr></thead>
                <tbody>
                    @foreach($events as $event)
                        <tr>
                            <td>{{ $event->id }}</td>
                            <td>{{ $event->title }}</td>
                            <td>{{ $event->type->label() }}</td>
                            <td><span class="admin-badge">{{ $event->status->label() }}</span></td>
                            <td>{{ $event->venue->name }}</td>
                            <td>{{ $event->starts_at->format('d.m.Y H:i') }}</td>
                            <td>{{ $event->participants_count }}{{ $event->max_participants ? ' / '.$event->max_participants : '' }}</td>
                            <td>
                                @if(in_array($event->status->value, ['published', 'completed'], true) && $event->visibility->value === 'public')
                                    <a class="btn btn--secondary btn--sm" href="{{ route('events.show', $event->routeIdentifier()) }}" target="_blank" rel="noopener">Просмотр</a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @include('theme::partials.admin.pagination', ['paginator' => $events])
    @endif
@endsection
