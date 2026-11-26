<form id="teams-category-filter-{{ $scope ?? 'desktop' }}" class="app-category__filters" method="GET" action="{{ route('teams.index') }}" aria-label="Поиск команд">
    @if(($categoryView ?? 'cards') === 'list')<input type="hidden" name="view" value="list">@endif
    @if(($scope ?? 'desktop') === 'mobile')
        <label><span>Название или описание</span><input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Название команды"></label>
    @endif
    <label class="app-category__toggle"><input type="checkbox" name="hiring" value="1" @checked($filters['hiring'])><span>Идёт набор</span></label>
    <details class="app-catalog-advanced" @if(filled($filters['sport_type']) || filled($filters['member_count'])) open @endif>
        <summary>Расширенные фильтры</summary>
        <div class="app-catalog-advanced__fields">
            <label><span>Формат</span>
                <span class="select-control"><select name="sport_type">
                    <option value="">Любой</option>
                    @foreach(\App\Modules\Team\Domain\Enums\TeamSportTypeEnum::cases() as $type)
                        <option value="{{ $type->value }}" @selected($filters['sport_type'] === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
            </label>
            <label><span>Размер состава</span>
                <span class="select-control"><select name="member_count">
                    <option value="">Любой</option>
                    <option value="small" @selected($filters['member_count'] === 'small')>До 5 участников</option>
                    <option value="medium" @selected($filters['member_count'] === 'medium')>6–10 участников</option>
                    <option value="large" @selected($filters['member_count'] === 'large')>От 11 участников</option>
                </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
            </label>
        </div>
    </details>
    <div class="app-category__filter-actions">
        @include('theme::components.catalog-reset', ['resetUrl' => $categoryResetUrl])
        <button class="button primary" type="submit">Применить</button>
    </div>
</form>
