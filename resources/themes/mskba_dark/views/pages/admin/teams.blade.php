@php $title = 'Команды'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Каркас раздела для будущих команд и составов.',
])

@section('section-content')
    <form method="GET" action="{{ route('admin.teams') }}" class="admin-filter">
        <label class="admin-filter__field">
            <span class="admin-filter__label">Поиск</span>
            <input class="form-control" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Название команды">
        </label>
        <label class="admin-filter__field">
            <span class="admin-filter__label">Статус</span>
            <select class="form-select" name="status" disabled>
                <option>Будет добавлено позже</option>
            </select>
        </label>
        <label class="admin-filter__field">
            <span class="admin-filter__label">Тип</span>
            <input class="form-control" type="text" value="Будет добавлено позже" disabled>
        </label>
        <div class="admin-filter__actions">
            <button type="submit" class="btn btn--primary btn--sm">Фильтр</button>
            <a href="{{ route('admin.teams') }}" class="btn btn--secondary btn--sm">Сброс</a>
        </div>
    </form>

    <div class="admin-empty">
        Домен команд еще не реализован. Страница закрепляет будущий формат списка, фильтров и пагинации.
    </div>
@endsection
