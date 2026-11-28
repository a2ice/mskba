<form id="news-category-filter-{{ $scope ?? 'desktop' }}" class="app-category__filters" method="GET" action="{{ route('news.index') }}">
    @if(($categoryView ?? 'list') === 'cards')<input type="hidden" name="view" value="cards">@endif
    @if(($scope ?? 'desktop') === 'mobile' || ($articleSidebar ?? false))
    <label><span>Поиск публикации</span>
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Название или описание">
    </label>
    @endif
    @if(($filters['type'] ?? '') === 'system_update')
        <input type="hidden" name="type" value="system_update">
    @else
    <details class="app-catalog-advanced" @if(filled($filters['type'] ?? null)) open @endif>
        <summary>Расширенные фильтры</summary>
        <div class="app-catalog-advanced__fields">
        <label><span>Тип публикации</span>
            <span class="select-control"><select name="type">
                <option value="">Все материалы</option>
                @foreach($filterTypes as $contentType)
                    <option value="{{ $contentType->value }}" @selected(($filters['type'] ?? '') === $contentType->value)>{{ $contentType->label() }}</option>
                @endforeach
            </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
        </label>
        </div>
    </details>
    @endif
    <div class="app-category__filter-actions">
        @include('theme::components.catalog-reset', ['resetUrl' => $categoryResetUrl])
        <button class="button primary" type="submit">Применить</button>
    </div>
</form>
