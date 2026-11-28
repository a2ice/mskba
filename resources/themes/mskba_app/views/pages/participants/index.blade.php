@php
    $categoryTitle = request()->routeIs('participants.players') ? 'Игроки'
        : (request()->routeIs('participants.coaches') ? 'Тренеры' : 'Участники');
    $categoryDescription = 'Поиск участников баскетбольного сообщества.';
    $categoryRootId = 'participants-catalog';
    $categoryView = request('view') === 'list' ? 'list' : 'cards';
    $categoryBaseUrl = url()->current();
    $categoryViewQuery = request()->except(['view', 'page', 'fragment']);
    $categoryResetUrl = $categoryBaseUrl.($categoryView === 'list' ? '?view=list' : '');
    $categorySidebarLabel = 'Навигация и фильтры участников';
    $categoryToolbarCount = 'Найдено участников: '.$participants->total();
    $categoryCompactToolbar = true;
    $categoryToolbarSearch = ['form' => 'participants-category-filter-desktop', 'name' => 'q', 'value' => $filters['q'] ?? '', 'placeholder' => 'Имя или никнейм', 'label' => 'Поиск участников', 'maxlength' => 100];
    $categoryVisibleCount = $participants->count();
    $categoryResultTotal = $participants->total();
@endphp
@extends('theme::layouts.category')

@section('category-sidebar')
    <h2>Участники</h2>
    @include('theme::pages.participants.partials.navigation')
    <div class="app-category__separator"></div>
    @include('theme::pages.participants.partials.category-filters', ['scope' => 'desktop'])
@endsection

@section('category-before-items')
    <details class="app-category__mobile-filters panel">
        <summary>Категории и фильтры <span aria-hidden="true">⌄</span></summary>
        <div class="app-category__mobile-filters-body">
            @include('theme::pages.participants.partials.navigation')
            @include('theme::pages.participants.partials.category-filters', ['scope' => 'mobile'])
        </div>
    </details>
@endsection

@section('category-items')
    @forelse($participants as $item)
        <article class="panel app-category__card app-participant-card">
            <div class="app-category__card-body">
                @include('theme::pages.participants.partials.public-avatar', ['avatarUrl' => $item['avatar_url'], 'avatarRestricted' => $item['avatar_restricted']])
                <div class="app-community-card-content">
                <h2>
                    @if($item['url'])
                        <a href="{{ $item['url'] }}">{{ $item['name'] }}</a>
                    @else
                        {{ $item['name'] }}
                    @endif
                </h2>
                @if($item['nickname'] && $item['nickname'] !== $item['name'])<p>{{ $item['nickname'] }}</p>@endif
                <div class="app-category__badges">
                    @foreach($item['roles'] as $role)
                        <span>{{ $role['label'] }}</span>
                    @endforeach
                </div>
                @if(! $item['url'])<p>Профиль закрыт</p>@endif
                </div>
            </div>
        </article>
    @empty
        <div class="panel app-category__empty">
            <h2>Участники не найдены</h2>
            <p>Попробуйте изменить поиск или роль.</p>
        </div>
    @endforelse
@endsection

@section('category-after-items')
    @if($participants->hasPages())
        <div class="app-category__pagination">{{ $participants->links() }}</div>
    @endif
@endsection
