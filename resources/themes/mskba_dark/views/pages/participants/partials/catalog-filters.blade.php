@php
    $formId = $formId ?? 'participant-catalog-filter-form';
    $scope = $scope ?? 'desktop';
    $currentView = $currentView ?? 'cards';
    $searchValue = $filters['q'] ?? '';
    $action = url()->current();
    $resetQuery = array_filter([
        'q' => filled($searchValue) ? $searchValue : null,
        'view' => $currentView === 'cards' ? null : $currentView,
    ]);
    $resetUrl = $presetRole
        ? route(request()->route()->getName(), $resetQuery)
        : route('participants.index', $resetQuery);
@endphp

<form
    id="{{ $formId }}"
    method="GET"
    action="{{ $action }}"
    class="default-category-filter participant-catalog-filter participant-catalog-filter--{{ $scope }}"
    data-default-category-filter-form
>
    <input type="hidden" name="q" value="{{ $searchValue }}">
    <input type="hidden" name="view" value="{{ $currentView === 'cards' ? '' : $currentView }}" data-default-category-view-input @disabled($currentView === 'cards')>

    <label class="default-category-filter__field">
        <span>Роль</span>
        @if($presetRole)
            <select class="form-select" disabled>
                <option>{{ $presetRole->label() }}</option>
            </select>
        @else
            <select name="role" class="form-select">
                <option value="">Все роли</option>
                @foreach($roleOptions as $roleOption)
                    <option value="{{ $roleOption->value }}" @selected(($filters['role'] ?? '') === $roleOption->value)>{{ $roleOption->label() }}</option>
                @endforeach
            </select>
        @endif
    </label>

    <div class="default-category-filter__actions">
        <button class="btn btn--primary btn--sm" type="submit">Применить</button>
        <a class="btn btn--secondary btn--sm" href="{{ $resetUrl }}">Сбросить</a>
    </div>
</form>
