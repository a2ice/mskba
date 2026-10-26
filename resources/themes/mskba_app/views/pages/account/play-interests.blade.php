@extends('theme::layouts.account', ['title' => 'Мои подписки'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Мои подписки',
        'intro' => 'Здесь ты можешь создавать, изменять и приостанавливать подписки на игры и площадки.',
    ])
@endsection

@section('account-content')
    <section class="panel app-play-interests" data-play-interests
        data-list-url="{{ route('play-intents.index') }}"
        data-pause-url="{{ url('/account/play-intents') }}"
        data-venues-url="{{ route('venues.search') }}">
        <div class="app-play-interests__top">
            <h2>Твои подписки</h2>
            <div class="app-play-interests__actions">
                <button class="button primary" type="button" data-play-intent-open>Хочу играть</button>
                <a class="button secondary" href="{{ route('venues.search') }}">Найти площадку</a>
            </div>
        </div>
        @php
            $interestDialogOptions = [
                'venueId' => null,
                'venueLatitude' => null,
                'venueLongitude' => null,
                'authenticated' => true,
                'endpoints' => [
                    'favoriteStatuses' => route('favorites.venue-statuses'),
                    'favoritesToggle' => route('favorites.toggle'),
                    'store' => route('play-intents.store'),
                    'base' => url('/account/play-intents'),
                    'amenities' => route('play-intents.amenities'),
                    'venuesSearch' => route('venues.search'),
                    'mine' => route('play-intents.mine'),
                ],
            ];
        @endphp
        <div data-mskba-play-intent-root data-options='@json($interestDialogOptions)'></div>
        <div data-play-interests-list role="region" aria-live="polite">
            <p class="muted">Загружаем подписки…</p>
        </div>
    </section>
@endsection
