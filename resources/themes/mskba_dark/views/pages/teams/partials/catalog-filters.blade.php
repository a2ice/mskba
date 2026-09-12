@php
    $formId = $formId ?? 'team-catalog-filter-form';
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
    action="{{ route('teams.index') }}"
    class="default-category-filter team-catalog-filter team-catalog-filter--{{ $scope }}"
    data-default-category-filter-form
>
    <input type="hidden" name="q" value="{{ $searchValue }}">
    <input type="hidden" name="view" value="{{ $currentView === 'cards' ? '' : $currentView }}" data-default-category-view-input @disabled($currentView === 'cards')>

    <label class="default-category-filter__field">
        <span>Размер состава</span>
        <select name="member_count" class="form-select">
            <option value="">Любой</option>
            <option value="small" @selected(($filters['member_count'] ?? '') === 'small')>До 5 участников</option>
            <option value="medium" @selected(($filters['member_count'] ?? '') === 'medium')>6–10 участников</option>
            <option value="large" @selected(($filters['member_count'] ?? '') === 'large')>11 и более</option>
        </select>
    </label>

    <label class="default-category-filter__field">
        <span>Тип команды</span>
        <select name="sport_type" class="form-select">
            <option value="">Любая</option>
            <option value="streetball" @selected(($filters['sport_type'] ?? '') === 'streetball')>Стритбольная</option>
            <option value="basketball" @selected(($filters['sport_type'] ?? '') === 'basketball')>Баскетбольная</option>
        </select>
    </label>

    <label class="default-category-filter__field">
        <span>Набор игроков</span>
        <select name="hiring" class="form-select">
            <option value="">Любой</option>
            <option value="1" @selected((bool) ($filters['hiring'] ?? false))>Идёт набор</option>
        </select>
    </label>

    <div class="default-category-filter__actions">
        <button class="btn btn--primary btn--sm" type="submit">Применить</button>
        <a class="btn btn--secondary btn--sm" href="{{ route('teams.index', $resetQuery) }}">Сбросить</a>
    </div>
</form>
