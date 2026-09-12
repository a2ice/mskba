@php
    $formId = $formId ?? 'tournament-catalog-filter-form';
    $scope = $scope ?? 'desktop';
    $currentView = $currentView ?? 'cards';
    $resetQuery = array_filter([
        'period' => $period === 'all' ? null : $period,
        'query' => filled($query) ? $query : null,
        'view' => $currentView === 'cards' ? null : $currentView,
    ]);
@endphp

<form
    id="{{ $formId }}"
    method="GET"
    action="{{ route('tournaments.index') }}"
    class="default-category-filter tournament-catalog-filter tournament-catalog-filter--{{ $scope }}"
    data-default-category-filter-form
>
    @if($period !== 'all')<input type="hidden" name="period" value="{{ $period }}">@endif
    @if(filled($query))<input type="hidden" name="query" value="{{ $query }}">@endif
    <input type="hidden" name="view" value="{{ $currentView === 'cards' ? '' : $currentView }}" data-default-category-view-input @disabled($currentView === 'cards')>

    <label class="default-category-filter__field">
        <span>Дата с</span>
        <input class="form-control" type="date" name="date_from" value="{{ $dateFrom }}">
    </label>

    <label class="default-category-filter__field">
        <span>Дата по</span>
        <input class="form-control" type="date" name="date_to" value="{{ $dateTo }}">
    </label>

    <div class="default-category-filter__actions">
        <button class="btn btn--primary btn--sm" type="submit">Применить</button>
        <a class="btn btn--secondary btn--sm" href="{{ route('tournaments.index', $resetQuery) }}">Сбросить</a>
    </div>
    @error('date_to') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
</form>
