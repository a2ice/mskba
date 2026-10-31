@extends('theme::layouts.section-sidebar', [
    'title' => 'Баннер · '.($season?->name ?? $league->name),
    'sectionId' => 'leagues',
    'sectionClass' => 'leagues-section',
    'contentTitle' => 'Баннер',
    'contentSubtitle' => $season?->name ?? $league->name,
])

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Лига и сезоны</h2>
        <ul class="sidebar-nav nav flex-column league-banner-menu">
            <li class="nav-item {{ $season === null ? 'active' : '' }}">
                <a class="nav-link {{ $season === null ? 'active' : '' }}"
                   href="{{ route('account.leagues.banner', $league->id) }}"
                   @if($season === null) aria-current="page" @endif>{{ $league->name }}</a>
            </li>
            @foreach($league->seasons()->orderByDesc('id')->get() as $item)
                <li class="nav-item {{ $season?->id === $item->id ? 'active' : '' }}">
                    <a class="nav-link {{ $season?->id === $item->id ? 'active' : '' }}"
                       href="{{ route('account.leagues.seasons.banner', [$league->id, $item->id]) }}"
                       @if($season?->id === $item->id) aria-current="page" @endif>{{ $item->name }}</a>
                </li>
            @endforeach
        </ul>
    </div>
@endsection

@section('section-content')
    @include('components.entity-banner-editor', ['model' => $bannerModel, 'context' => 'account'])
@endsection
