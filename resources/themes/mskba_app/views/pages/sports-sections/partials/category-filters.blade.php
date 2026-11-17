<form id="sections-category-filter-{{ $scope ?? 'desktop' }}" class="app-category__filters" method="GET" action="{{ route('sports-sections.index') }}">
    @if(($categoryView ?? 'cards') === 'list')<input type="hidden" name="view" value="list">@endif
    <label><span>Поиск секции</span>
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Название или описание" maxlength="120">
    </label>
    <label><span>Режим тренировок</span>
        <span class="select-control"><select name="training_mode">
            <option value="">Все режимы</option>
            @foreach($trainingModes as $mode)
                <option value="{{ $mode->value }}" @selected(($filters['training_mode'] ?? '') === $mode->value)>{{ $mode->label() }}</option>
            @endforeach
        </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
    </label>
    <label><span>Формат игры</span>
        <span class="select-control"><select name="game_format">
            <option value="">Все форматы</option>
            @foreach($formats as $format)
                <option value="{{ $format->value }}" @selected(($filters['game_format'] ?? '') === $format->value)>{{ $format->label() }}</option>
            @endforeach
        </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
    </label>
    <label><span>Стоимость</span>
        <span class="select-control"><select name="pricing_type">
            <option value="">Любая</option>
            @foreach($pricingTypes as $priceType)
                <option value="{{ $priceType->value }}" @selected(($filters['pricing_type'] ?? '') === $priceType->value)>{{ $priceType->label() }}</option>
            @endforeach
        </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
    </label>
    <label><span>Площадка</span>
        <span class="select-control"><select name="venue_id">
            <option value="">Все площадки</option>
            @foreach($venues as $venue)
                <option value="{{ $venue->id }}" @selected((int) ($filters['venue_id'] ?? 0) === (int) $venue->id)>{{ $venue->name }}</option>
            @endforeach
        </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
    </label>
    <label><span>Команда</span>
        <span class="select-control"><select name="team_id">
            <option value="">Все команды</option>
            @foreach($teams as $team)
                <option value="{{ $team->id }}" @selected((int) ($filters['team_id'] ?? 0) === (int) $team->id)>{{ $team->name }}</option>
            @endforeach
        </select><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
    </label>
    <label class="app-category__toggle"><input type="checkbox" name="accepts_requests" value="1" @checked($filters['accepts_requests'] ?? false)><span>Принимают заявки</span></label>
    <label class="app-category__toggle"><input type="checkbox" name="recruiting" value="1" @checked($filters['recruiting'] ?? false)><span>Идёт набор</span></label>
    <div class="app-category__filter-actions">
        <button class="button primary" type="submit">Применить</button>
    </div>
</form>
