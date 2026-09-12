@php
    $formId = $formId ?? 'venue-catalog-filter-form';
    $scope = $scope ?? 'desktop';
    $searchValue = $filters['search'] ?? '';
    $currentView = $currentView ?? ($filters['view'] ?? 'cards');
@endphp

<form
    id="{{ $formId }}"
    method="GET"
    action="{{ route('venues') }}"
    class="default-category-filter venue-catalog-filter venue-catalog-filter--{{ $scope }}"
    data-default-category-filter-form
>
    <input type="hidden" name="search" value="{{ $searchValue }}">
    <input
        type="hidden"
        name="view"
        value="{{ $currentView === 'cards' ? '' : $currentView }}"
        data-default-category-view-input
        @disabled($currentView === 'cards')
    >

    <label class="default-category-filter__field">
        <span>Тип площадки</span>
        <select name="type" class="form-select">
            <option value="">Все типы</option>
            @foreach($types as $type)
                <option value="{{ $type->value }}" @selected(($filters['type'] ?? '') === $type->value)>{{ $type->label() }}</option>
            @endforeach
        </select>
    </label>

    <label class="default-category-filter__field">
        <span>Состояние</span>
        <select name="operational_status" class="form-select">
            <option value="">Любое</option>
            @foreach($operationalStatuses as $status)
                <option value="{{ $status->value }}" @selected(($filters['operational_status'] ?? '') === $status->value)>{{ $status->label() }}</option>
            @endforeach
        </select>
    </label>

    <label class="default-category-filter__field">
        <span>Доступ</span>
        <select name="access" class="form-select">
            <option value="">Любой</option>
            <option value="free" @selected(($filters['access'] ?? '') === 'free')>Бесплатно</option>
            <option value="paid" @selected(($filters['access'] ?? '') === 'paid')>Платно</option>
            <option value="approval" @selected(($filters['access'] ?? '') === 'approval')>По подтверждению</option>
        </select>
    </label>

    <div class="default-category-filter__actions">
        <button class="btn btn--primary btn--sm" type="submit">Применить</button>
        <a class="btn btn--secondary btn--sm" href="{{ route('venues') }}">Сбросить</a>
    </div>
</form>
