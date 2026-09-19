@php
    $title = 'Участники';
    $currentView = request('view') === 'list' ? 'list' : 'cards';
    $activeFilterCount = $presetRole === null && filled($filters['role'] ?? null) ? 1 : 0;
@endphp

@extends('theme::layouts.default-category', [
    'title' => $title,
    'categoryId' => 'participants',
    'categoryClass' => 'participants-category-catalog',
    'currentView' => $currentView,
    'hasMap' => false,
    'mobileFilterModalId' => 'participant-catalog-filters',
])

@section('category-navigation')
    @include('theme::pages.participants.partials.catalog-navigation')
@endsection

@section('category-filters-desktop')
    @include('theme::pages.participants.partials.catalog-filters', [
        'formId' => 'participant-catalog-filter-form-desktop',
        'scope' => 'desktop',
        'currentView' => $currentView,
    ])
@endsection

@section('category-search')
    <form id="participant-catalog-search-form" method="GET" action="{{ url()->current() }}">
        <label class="catalog-toolbar__search" aria-label="Поиск участников">
            <i class="ti ti-search" aria-hidden="true"></i>
            <input
                type="search"
                name="q"
                value="{{ $filters['q'] }}"
                placeholder="Имя или никнейм"
                data-default-category-search
            >
        </label>
        @if($presetRole === null && filled($filters['role'] ?? null))
            <input type="hidden" name="role" value="{{ $filters['role'] }}">
        @endif
        <input type="hidden" name="view" value="{{ $currentView === 'cards' ? '' : $currentView }}" data-default-category-view-input @disabled($currentView === 'cards')>
    </form>
@endsection

@section('category-active-filter-count')
    @if($activeFilterCount > 0){{ $activeFilterCount }}@endif
@endsection

@section('category-results-cards')
    <div class="default-category-results--cards participant-category-results participant-category-results--cards">
        @forelse($participants as $item)
            @include('theme::pages.participants.partials.catalog-item', ['item' => $item, 'mode' => 'card'])
        @empty
            <div class="default-category__empty participant-category-catalog__empty">
                <i class="ti ti-users-off" aria-hidden="true"></i>
                <strong>Участники не найдены</strong>
                <span>Попробуйте изменить условия поиска или выбрать другой раздел.</span>
                <a class="btn btn--secondary btn--sm" href="{{ route('participants.index') }}">Сбросить параметры</a>
            </div>
        @endforelse
    </div>
@endsection

@section('category-results-list')
    <div class="default-category-results--list participant-category-results participant-category-results--list">
        @forelse($participants as $item)
            @include('theme::pages.participants.partials.catalog-item', ['item' => $item, 'mode' => 'list'])
        @empty
            <div class="default-category__empty participant-category-catalog__empty">
                <i class="ti ti-users-off" aria-hidden="true"></i>
                <strong>Участники не найдены</strong>
                <span>Попробуйте изменить условия поиска или выбрать другой раздел.</span>
                <a class="btn btn--secondary btn--sm" href="{{ route('participants.index') }}">Сбросить параметры</a>
            </div>
        @endforelse
    </div>
@endsection

@section('category-pagination')
    @if($participants->hasPages())
        <div class="participant-category-catalog__pagination">{{ $participants->links('theme::partials.pagination') }}</div>
    @endif
@endsection

@section('category-mobile-filters')
    @component('theme::partials.modal.layout', ['id' => 'participant-catalog-filters', 'dialogClass' => 'default-category-mobile-filters__dialog'])
        <h2 class="modal_title" id="modal-title-participant-catalog-filters">Фильтры участников</h2>
        @include('theme::pages.participants.partials.catalog-filters', [
            'formId' => 'participant-catalog-filter-form-mobile',
            'scope' => 'mobile',
            'currentView' => $currentView,
        ])
    @endcomponent
@endsection
