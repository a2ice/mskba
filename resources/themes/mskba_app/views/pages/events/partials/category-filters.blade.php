<form id="events-category-filter-{{ $scope ?? 'desktop' }}" class="app-category__filters" method="GET" action="{{ route('events.index') }}">
    @if(($categoryView ?? 'cards') === 'list')<input type="hidden" name="view" value="list">@endif
    <label><span>Поиск мероприятия</span>
        <input name="q" type="search" value="{{ $search }}" maxlength="100" placeholder="Название, описание, площадка">
    </label>
    <label><span>Тип мероприятия</span>
        <span class="select-control"><select name="type">
            <option value="">Все виды</option>
            <option value="games" @selected($typeFilter === 'games')>Все игры</option>
            @foreach($types as $eventType)
                <option value="{{ $eventType->value }}" @selected($typeFilter === $eventType->value)>{{ $eventType->label() }}</option>
            @endforeach
        </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
    </label>
    <label><span>Период</span>
        <span class="select-control"><select name="period">
            <option value="upcoming" @selected($period === 'upcoming')>Предстоящие</option>
            <option value="past" @selected($period === 'past')>Прошедшие</option>
        </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
    </label>
    <label><span>Дата с</span><input type="date" name="date_from" value="{{ $dateFrom }}"></label>
    <label><span>Дата по</span><input type="date" name="date_to" value="{{ $dateTo }}"></label>
    <label><span>Площадка</span>
        <span class="select-control"><select name="venue_id">
            <option value="">Все площадки</option>
            @foreach($filterVenues as $venue)
                <option value="{{ $venue->id }}" @selected($venueId === (int) $venue->id)>{{ $venue->name }}</option>
            @endforeach
        </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
    </label>
    <label><span>Статус</span>
        <span class="select-control"><select name="status">
            <option value="not_cancelled" @selected($statusFilter === 'not_cancelled')>Кроме отменённых</option>
            <option value="all" @selected($statusFilter === 'all')>Все</option>
            <option value="cancelled" @selected($statusFilter === 'cancelled')>Отменённые</option>
        </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
    </label>
    @if($period === 'past')
        <label><span>Результат</span>
            <span class="select-control"><select name="outcome">
                <option value="">Все</option>
                <option value="completed" @selected($outcome === 'completed')>Завершённые</option>
                <option value="cancelled" @selected($outcome === 'cancelled')>Отменённые</option>
                <option value="unmarked" @selected($outcome === 'unmarked')>Без результата</option>
            </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
        </label>
    @endif
    <label class="app-category__toggle"><input type="checkbox" name="has_mini_games" value="1" @checked($hasMiniGames)><span>С мини-играми</span></label>
    <div class="app-category__filter-actions">
        <button class="button primary" type="submit">Применить</button>
    </div>
</form>
