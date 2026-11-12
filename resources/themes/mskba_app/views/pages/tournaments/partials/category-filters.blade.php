<form id="tournaments-category-filter-{{ $scope ?? 'desktop' }}" class="app-category__filters" method="GET" action="{{ route('tournaments.index') }}">
    @if(($categoryView ?? 'cards') === 'list')<input type="hidden" name="view" value="list">@endif
    <label><span>Название турнира</span>
        <input type="search" name="query" value="{{ $query ?? '' }}" maxlength="120" placeholder="Найти турнир">
    </label>
    <label><span>Период</span>
        <span class="select-control"><select name="period">
            <option value="all" @selected($period === 'all')>Все турниры</option>
            <option value="current" @selected($period === 'current')>Текущие</option>
            <option value="upcoming" @selected($period === 'upcoming')>Предстоящие</option>
            <option value="past" @selected($period === 'past')>Прошедшие</option>
        </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
    </label>
    <label><span>Дата с</span><input type="date" name="date_from" value="{{ $dateFrom ?? '' }}"></label>
    <label><span>Дата по</span><input type="date" name="date_to" value="{{ $dateTo ?? '' }}"></label>
    <div class="app-category__filter-actions">
        <button class="button primary" type="submit">Применить</button>
    </div>
</form>
