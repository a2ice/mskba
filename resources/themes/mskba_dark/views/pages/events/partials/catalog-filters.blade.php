@php
    $formId = $formId ?? 'event-catalog-filter-form';
    $scope = $scope ?? 'desktop';
    $currentView = $currentView ?? 'cards';
    $resetQuery = array_filter([
        'q' => filled($search) ? $search : null,
        'view' => $currentView === 'cards' ? null : $currentView,
    ]);
@endphp

<form id="{{ $formId }}" method="GET" action="{{ route('events.index') }}" class="default-category-filter event-catalog-filter event-catalog-filter--{{ $scope }}" data-event-catalog-filter-form>
    <input type="hidden" name="q" value="{{ $search }}">
    <input type="hidden" name="view" value="{{ $currentView === 'cards' ? '' : $currentView }}" data-default-category-view-input @disabled($currentView === 'cards')>

    <label class="default-category-filter__field">
        <span>Период</span>
        <select name="period" class="form-select">
            <option value="upcoming" @selected($period !== 'past')>Предстоящие</option>
            <option value="past" @selected($period === 'past')>Прошедшие</option>
        </select>
    </label>

    <label class="default-category-filter__field">
        <span>Тип мероприятия</span>
        <select name="type" class="form-select">
            <option value="">Все типы</option>
            <option value="games" @selected($typeFilter === 'games')>Игры и игровые тренировки</option>
            @foreach($types as $type)
                <option value="{{ $type->value }}" @selected($selectedType === $type)>{{ $type->label() }}</option>
            @endforeach
        </select>
    </label>

    <label class="default-category-filter__field">
        <span>Статус</span>
        <select name="status" class="form-select">
            <option value="all" @selected($statusFilter === 'all')>Все</option>
            <option value="not_cancelled" @selected($statusFilter === 'not_cancelled')>Не отменённые</option>
            <option value="cancelled" @selected($statusFilter === 'cancelled')>Отменённые</option>
        </select>
    </label>

    <label class="default-category-filter__field">
        <span>Площадка</span>
        <select name="venue_id" class="form-select">
            <option value="">Все площадки</option>
            @foreach($filterVenues as $venue)
                <option value="{{ $venue->id }}" @selected($venueId === $venue->id)>{{ $venue->name }}</option>
            @endforeach
        </select>
    </label>

    <label class="default-category-filter__field">
        <span>Дата с</span>
        <input class="form-control" type="date" name="date_from" value="{{ $dateFrom }}">
    </label>

    <label class="default-category-filter__field">
        <span>Дата по</span>
        <input class="form-control" type="date" name="date_to" value="{{ $dateTo }}">
    </label>

    @if($period === 'past')
        <label class="default-category-filter__field">
            <span>Итог</span>
            <select name="outcome" class="form-select">
                <option value="">Все итоги</option>
                <option value="completed" @selected($outcome === 'completed')>Состоялось</option>
                <option value="unmarked" @selected($outcome === 'unmarked')>Итог не указан</option>
            </select>
        </label>
    @endif

    <div class="event-catalog-filter__toggle">
        <input type="hidden" name="has_mini_games" value="0">
        <label class="form-toggle" for="{{ $formId }}-has-mini-games">
            <input id="{{ $formId }}-has-mini-games" class="form-toggle__input" type="checkbox" name="has_mini_games" value="1" @checked($hasMiniGames)>
            <span class="form-toggle__control" aria-hidden="true"></span>
            <strong class="form-toggle__title">Есть мини-игры</strong>
        </label>
    </div>

    <div class="default-category-filter__actions">
        <button class="btn btn--primary btn--sm" type="submit">Применить</button>
        <a class="btn btn--secondary btn--sm" href="{{ route('events.index', $resetQuery) }}">Сбросить</a>
    </div>

    @error('date_to') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
</form>
