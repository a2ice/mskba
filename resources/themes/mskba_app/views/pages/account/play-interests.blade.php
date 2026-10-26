@extends('theme::layouts.account', ['title' => 'Мои подписки'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Хочу играть',
        'intro' => 'Здесь сохраняются места, расписание и другие условия, в которых тебе хотелось бы играть.',
    ])
@endsection

@section('account-content')
    <section class="panel app-play-interests" data-play-interests
        data-list-url="{{ route('play-intents.index') }}"
        data-pause-url="{{ url('/account/play-intents') }}"
        data-venues-url="{{ route('venues.search') }}">
        <div class="app-play-interests__top">
            <h2>Мои подписки</h2>
            <a class="button secondary" href="{{ route('venues.search') }}">Найти площадку</a>
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
            <p class="muted">Загружаем интересы…</p>
        </div>
    </section>
@endsection
