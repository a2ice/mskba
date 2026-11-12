<form id="leagues-category-filter-{{ $scope ?? 'desktop' }}" class="app-category__filters" method="GET" action="{{ route('leagues.index') }}">
    @if(($categoryView ?? 'cards') === 'list')<input type="hidden" name="view" value="list">@endif
    <label><span>Название или описание</span>
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="120" placeholder="Название лиги">
    </label>
    <label><span>Статус</span>
        <span class="select-control"><select name="status">
            <option value="">Все</option>
            <option value="active" @selected(($filters['status'] ?? '') === 'active')>Действующие</option>
            <option value="archived" @selected(($filters['status'] ?? '') === 'archived')>Архивные</option>
        </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
    </label>
    <label><span>Сезон</span>
        <span class="select-control"><select name="season">
            <option value="">Все сезоны</option>
            @foreach($seasons as $season)
                <option value="{{ $season->id }}" @selected((string) ($filters['season'] ?? '') === (string) $season->id)>{{ $season->league->name }} — {{ $season->name }}</option>
            @endforeach
        </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
    </label>
    <div class="app-category__filter-actions">
        <button class="button primary" type="submit">Применить</button>
    </div>
</form>
