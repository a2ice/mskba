@php
    $formId = $formId ?? 'sports-section-catalog-filter-form';
    $scope = $scope ?? 'desktop';
    $currentView = $currentView ?? 'cards';
    $searchValue = $filters['q'] ?? '';
    $resetQuery = array_filter([
        'q' => filled($searchValue) ? $searchValue : null,
        'view' => $currentView === 'cards' ? null : $currentView,
    ]);
@endphp

<form
    id="{{ $formId }}"
    method="GET"
    action="{{ route('sports-sections.index') }}"
    class="default-category-filter sports-section-catalog-filter sports-section-catalog-filter--{{ $scope }}"
    data-default-category-filter-form
>
    <input type="hidden" name="q" value="{{ $searchValue }}">
    <input type="hidden" name="view" value="{{ $currentView === 'cards' ? '' : $currentView }}" data-default-category-view-input @disabled($currentView === 'cards')>

    <label class="default-category-filter__field">
        <span>Формат тренировок</span>
        <select name="training_mode" class="form-select">
            <option value="">Любой</option>
            @foreach($trainingModes as $item)
                <option value="{{ $item->value }}" @selected(($filters['training_mode'] ?? '') === $item->value)>{{ $item->label() }}</option>
            @endforeach
        </select>
    </label>

    <label class="default-category-filter__field">
        <span>Направление</span>
        <select name="game_format" class="form-select">
            <option value="">Любое</option>
            @foreach($formats as $item)
                <option value="{{ $item->value }}" @selected(($filters['game_format'] ?? '') === $item->value)>{{ $item->label() }}</option>
            @endforeach
        </select>
    </label>

    <label class="default-category-filter__field">
        <span>Стоимость</span>
        <select name="pricing_type" class="form-select">
            <option value="">Любая</option>
            @foreach($pricingTypes as $item)
                <option value="{{ $item->value }}" @selected(($filters['pricing_type'] ?? '') === $item->value)>{{ $item->label() }}</option>
            @endforeach
        </select>
    </label>

    <label class="default-category-filter__field">
        <span>Площадка</span>
        <select name="venue_id" class="form-select">
            <option value="">Любая</option>
            @foreach($venues as $venue)
                <option value="{{ $venue->id }}" @selected((string) ($filters['venue_id'] ?? '') === (string) $venue->id)>{{ $venue->name }}</option>
            @endforeach
        </select>
    </label>

    <label class="default-category-filter__field">
        <span>Связанная команда</span>
        <select name="team_id" class="form-select">
            <option value="">Любая</option>
            @foreach($teams as $team)
                <option value="{{ $team->id }}" @selected((string) ($filters['team_id'] ?? '') === (string) $team->id)>{{ $team->name }}</option>
            @endforeach
        </select>
    </label>

    <div class="sports-section-catalog-filter__toggle">
        <input type="hidden" name="accepts_requests" value="0">
        <label class="form-toggle" for="{{ $formId }}-accepts-requests">
            <input id="{{ $formId }}-accepts-requests" class="form-toggle__input" type="checkbox" name="accepts_requests" value="1" @checked((bool) ($filters['accepts_requests'] ?? false))>
            <span class="form-toggle__control" aria-hidden="true"></span>
            <strong class="form-toggle__title">Принимает заявки</strong>
        </label>
    </div>

    <div class="sports-section-catalog-filter__toggle">
        <input type="hidden" name="recruiting" value="0">
        <label class="form-toggle" for="{{ $formId }}-recruiting">
            <input id="{{ $formId }}-recruiting" class="form-toggle__input" type="checkbox" name="recruiting" value="1" @checked((bool) ($filters['recruiting'] ?? false))>
            <span class="form-toggle__control" aria-hidden="true"></span>
            <strong class="form-toggle__title">Активно ведёт набор</strong>
        </label>
    </div>

    <div class="default-category-filter__actions">
        <button class="btn btn--primary btn--sm" type="submit">Применить</button>
        <a class="btn btn--secondary btn--sm" href="{{ route('sports-sections.index', $resetQuery) }}">Сбросить</a>
    </div>
</form>
