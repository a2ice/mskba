@extends('theme::layouts.account', ['title' => 'Мои интересы'])

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
            <h2>Мои интересы</h2>
            <a class="button secondary" href="{{ route('venues.search') }}">Найти площадку</a>
        </div>
        @php
            $interestDialogOptions = [
                'venueId' => null,
                'venueLatitude' => null,
                'venueLongitude' => null,
                'authenticated' => true,
                'endpoints' => [
                    'store' => route('play-intents.store'),
                    'base' => url('/account/play-intents'),
                    'amenities' => route('play-intents.amenities'),
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
