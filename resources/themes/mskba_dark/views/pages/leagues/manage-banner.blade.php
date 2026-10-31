@extends('theme::layouts.section-sidebar', [
    'title' => 'Баннер · '.($season?->name ?? $league->name),
    'sectionId' => 'leagues',
    'sectionClass' => 'leagues-section',
    'contentTitle' => 'Баннер · '.($season?->name ?? $league->name),
    'contentSubtitle' => 'Оформление публичной страницы',
])
@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Лига</h2>
        <ul class="sidebar-nav nav flex-column">
            <li class="nav-item"><a class="nav-link" href="{{ route('account.leagues.banner', $league->id) }}">{{ $league->name }}</a></li>
            @foreach($league->seasons()->orderByDesc('id')->get() as $item)
                <li class="nav-item"><a class="nav-link" href="{{ route('account.leagues.seasons.banner', [$league->id, $item->id]) }}">{{ $item->name }}</a></li>
            @endforeach
        </ul>
    </div>
@endsection
@section('section-content')
    @include('components.entity-banner-editor', ['model' => $bannerModel, 'context' => 'account'])
@endsection
