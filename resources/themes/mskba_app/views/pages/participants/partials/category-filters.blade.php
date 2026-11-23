<form id="participants-category-filter-{{ $scope ?? 'desktop' }}"
      class="app-category__filters" method="GET" action="{{ url()->current() }}"
      @if(($scope ?? '') === 'mobile') data-participant-catalog-filters @endif>
    @if(($categoryView ?? 'cards') === 'list')
        <input type="hidden" name="view" value="list">
    @endif
    <label><span>Поиск участника</span>
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}"
               maxlength="100" placeholder="Имя или никнейм">
    </label>
    @if($presetRole === null)
        <label><span>Роль</span>
            <span class="select-control">
                <select name="role">
                    <option value="">Все участники</option>
                    @foreach($roleOptions as $roleOption)
                        <option value="{{ $roleOption->value }}" @selected(($filters['role'] ?? '') === $roleOption->value)>{{ $roleOption->label() }}</option>
                    @endforeach
                </select>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </span>
        </label>
    @endif
    <div class="app-category__filter-actions">
        <button type="submit" class="button primary">Применить</button>
    </div>
</form>
