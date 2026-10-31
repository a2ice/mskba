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
            <li class="nav-item @if(!$season) active @endif"><a class="nav-link @if(!$season) active @endif" href="{{ route('account.leagues.banner', $league->id) }}" @if(!$season) aria-current="page" @endif>Лига: {{ $league->name }}</a></li>
            @foreach($league->seasons()->orderByDesc('id')->get() as $item)
                <li class="nav-item @if((int) $season?->id === (int) $item->id) active @endif"><a class="nav-link @if((int) $season?->id === (int) $item->id) active @endif" href="{{ route('account.leagues.seasons.banner', [$league->id, $item->id]) }}" @if((int) $season?->id === (int) $item->id) aria-current="page" @endif>Сезон: {{ $item->name }}</a></li>
            @endforeach
        </ul>
    </div>
@endsection
@section('section-content')
    @include('components.entity-banner-editor', ['model' => $bannerModel, 'context' => 'account'])
@endsection
