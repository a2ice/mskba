@php
    $title = 'Найти команду';
    $categoryTitle = $title;
    $categoryDescription = 'Подбери команду по формату, составу и наличию свободных мест.';
    $categoryRootId = 'teams-catalog';
    $categoryHeadingId = 'team-catalog-heading';
    $categoryRootClass = 'app-category app-team-section app-team-public';
    $categorySidebarLabel = 'Фильтры команд';
    $categoryView = request('view') === 'list' ? 'list' : 'cards';
    $categoryBaseUrl = route('teams.index');
    $categoryViewQuery = request()->except(['view', 'page', 'fragment']);
    $categoryResetUrl = $categoryBaseUrl.($categoryView === 'list' ? '?view=list' : '');
    $categoryCompactToolbar = true;
    $categoryToolbarSearch = ['form' => 'teams-category-filter-desktop', 'name' => 'q', 'value' => $filters['q'], 'placeholder' => 'Название команды', 'label' => 'Поиск команд'];
    $categoryVisibleCount = $teams->count();
    $categoryResultTotal = $teams->total();
@endphp
@extends('theme::layouts.category')

@section('category-heading-action')
    <div class="app-team-actions">
        @auth <a class="button secondary" href="{{ route('account.teams') }}">Мои команды</a> @endauth
        @can('team-create') <a class="button secondary" href="{{ route('teams.create') }}">Создать команду</a> @endcan
    </div>
@endsection

@section('category-sidebar')
    <h2>Участники</h2>
    @include('theme::pages.participants.partials.navigation')
    <div class="app-category__separator"></div>
    @include('theme::pages.teams.partials.category-filters', ['scope' => 'desktop'])
@endsection

@section('category-before-items')
    <details class="app-category__mobile-filters panel">
        <summary>Категории и фильтры <span aria-hidden="true">⌄</span></summary>
        <div class="app-category__mobile-filters-body">
            @include('theme::pages.participants.partials.navigation')
            @include('theme::pages.teams.partials.category-filters', ['scope' => 'mobile'])
        </div>
    </details>
@endsection

@section('category-items')
            @forelse ($teams as $team)
                <article class="panel app-team-card">
                    <div class="app-team-card__top">
                        @include('theme::pages.teams.partials.public-logo')
                        <div class="app-community-card-content">
                    <div class="app-team-card__title">
                        <h2><a href="{{ route('teams.show', $team->routeIdentifier()) }}">{{ $team->name }}</a></h2>
                        @if ($team->active_hiring_positions_count > 0)
                            <span class="badge success">Идёт набор</span>
                        @endif
                    </div>
                    <div class="app-team-card__sports">
                        @foreach ($team->sportProfiles as $profile)
                            <span class="badge">{{ $profile->sport_type->label() }}</span>
                        @endforeach
                    </div>
                    </div>
                    </div>
                    @if(filled($team->description))
                        <p class="app-team-card__detail">{{ \Illuminate\Support\Str::limit($team->description, 120) }}</p>
                    @endif
                    <p class="app-team-card__detail">Участников: {{ $team->active_memberships_count }}</p>
                    @if($team->active_hiring_positions_count > 0)
                        <p class="app-team-card__detail">Открытых вакансий: {{ $team->active_hiring_positions_count }}</p>
                    @endif
                    <a class="button secondary" href="{{ route('teams.show', $team->routeIdentifier()) }}">Посмотреть команду</a>
                </article>
            @empty
                <section class="panel app-team-empty">
                    <h2>По твоим фильтрам команд пока нет</h2>
                    <p>Измени условия поиска или посмотри все команды.</p>
                    <a class="button secondary" href="{{ route('teams.index') }}">Все команды</a>
                </section>
            @endforelse

@endsection

@section('category-after-items')
    <div class="app-team-pagination">{{ $teams->appends(request()->except('page'))->links() }}</div>
@endsection
