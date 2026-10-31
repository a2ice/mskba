@extends('theme::layouts.account', ['title' => 'Баннер · '.($season?->name ?? $league->name)])
@section('account-heading')
    <h1>Баннер · {{ $season?->name ?? $league->name }}</h1>
    <p>Широкое изображение для публичной страницы, отдельно от логотипа.</p>
@endsection
@section('account-content')
    <nav class="panel" aria-label="Оформление лиги и сезонов">
        <a href="{{ route('account.leagues.banner', $league->id) }}">Лига: {{ $league->name }}</a>
        @foreach($league->seasons()->orderByDesc('id')->get() as $item)
            <a href="{{ route('account.leagues.seasons.banner', [$league->id, $item->id]) }}">{{ $item->name }}</a>
        @endforeach
    </nav>
    @include('components.entity-banner-editor', ['model' => $bannerModel, 'context' => 'account'])
@endsection
