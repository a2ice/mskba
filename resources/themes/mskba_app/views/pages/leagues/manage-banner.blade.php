@extends('theme::layouts.account', ['title' => 'Оформление · '.($season?->name ?? $league->name)])
@section('account-heading')
    <header class="app-account-overview__intro">
        <h1>Оформление {{ $season ? 'сезона' : 'лиги' }}</h1>
        <p>{{ $season?->name ?? $league->name }}</p>
    </header>
@endsection
@section('account-content')
    <nav class="panel app-league-banner-nav" aria-label="Лига и сезоны">
        <h2>Лига и сезоны</h2>
        <div class="app-league-banner-nav__links">
            <a class="button secondary" href="{{ route('account.leagues.banner', $league->id) }}"
                @if(!$season) aria-current="page" @endif>Лига: {{ $league->name }}</a>
            @foreach($league->seasons()->orderByDesc('id')->get() as $item)
                <a class="button secondary" href="{{ route('account.leagues.seasons.banner', [$league->id, $item->id]) }}"
                    @if((int) $season?->id === (int) $item->id) aria-current="page" @endif>{{ $item->name }}</a>
            @endforeach
        </div>
    </nav>

    @include('components.entity-banner-editor', ['model' => $bannerModel, 'context' => 'account'])
@endsection
