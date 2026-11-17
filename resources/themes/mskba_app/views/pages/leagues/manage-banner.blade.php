@extends('theme::layouts.account', ['title' => 'Баннер · '.($season?->name ?? $league->name)])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Баннер',
        'intro' => $season?->name ?? $league->name,
    ])
@endsection

@section('account-content')
    <div class="app-league-banner-workspace">
        <nav class="panel app-league-banner-nav" aria-label="Баннеры лиги и сезонов">
            <h2>Лига и сезоны</h2>
            <div class="app-league-banner-tabs">
                <a href="{{ route('account.leagues.banner', $league->id) }}"
                   class="app-league-banner-tab {{ $season === null ? 'is-active' : '' }}"
                   @if($season === null) aria-current="page" @endif>
                    <span>{{ $league->name }}</span>
                </a>
                @foreach($league->seasons()->orderByDesc('id')->get() as $item)
                    <a href="{{ route('account.leagues.seasons.banner', [$league->id, $item->id]) }}"
                       class="app-league-banner-tab {{ $season?->id === $item->id ? 'is-active' : '' }}"
                       @if($season?->id === $item->id) aria-current="page" @endif>
                        <span>{{ $item->name }}</span>
                    </a>
                @endforeach
            </div>
        </nav>
        @include('components.entity-banner-editor', ['model' => $bannerModel, 'context' => 'account'])
    </div>
@endsection
