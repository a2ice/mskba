<form id="news-category-filter-{{ $scope ?? 'desktop' }}" class="app-category__filters" method="GET" action="{{ route('news.index') }}">
    @if(($categoryView ?? 'cards') === 'list')<input type="hidden" name="view" value="list">@endif
    <label><span>Поиск публикации</span>
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Название или описание">
    </label>
    <label><span>Тип публикации</span>
        <span class="select-control"><select name="type">
            <option value="">Все материалы</option>
            @foreach($types as $contentType)
                <option value="{{ $contentType->value }}" @selected(($filters['type'] ?? '') === $contentType->value)>{{ $contentType->label() }}</option>
            @endforeach
        </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
    </label>
    <div class="app-category__filter-actions">
        <button class="button primary" type="submit">Применить</button>
    </div>
</form>
