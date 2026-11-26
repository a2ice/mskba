<form id="tournaments-category-filter-{{ $scope ?? 'desktop' }}" class="app-category__filters" method="GET" action="{{ route('tournaments.index') }}">
    @if(($categoryView ?? 'cards') === 'list')<input type="hidden" name="view" value="list">@endif
    @if(($scope ?? 'desktop') === 'mobile')
    <label><span>Название турнира</span>
        <input type="search" name="query" value="{{ $query ?? '' }}" maxlength="120" placeholder="Найти турнир">
    </label>
    @endif

    <details class="app-catalog-advanced" @if($period !== 'all' || filled($dateFrom ?? null) || filled($dateTo ?? null)) open @endif>
        <summary>Расширенные фильтры</summary>
        <div class="app-catalog-advanced__fields">
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
        </div>
    </details>
    <div class="app-category__filter-actions">
        @include('theme::components.catalog-reset', ['resetUrl' => $categoryResetUrl])
        <button class="button primary" type="submit">Применить</button>
    </div>
</form>
