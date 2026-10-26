@extends('theme::layouts.account', ['title' => 'Мои подписки'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Мои подписки',
        'intro' => 'Управляй своими подписками, настраивай уведомления и следи за обновлениями в одном месте.',
    ])
@endsection

@section('account-content')
    <section class="panel app-play-interests" data-play-interests
        data-list-url="{{ route('play-intents.index') }}"
        data-pause-url="{{ url('/account/play-intents') }}"
        data-venues-url="{{ route('venues.search') }}">
        <form class="app-play-interests__filters" data-subscriptions-filters aria-label="Фильтры подписок">
            <label class="field-group app-play-interests__filter-field" for="account-subscription-type">
                <span>Тип подписки</span>
                <span class="select-control">
                    <select id="account-subscription-type" name="type">
                        <option value="all">Все типы</option>
                        <option value="play">Игровые подписки</option>
                    </select>
                    <svg aria-hidden="true"><use href="#chevron-down"/></svg>
                </span>
            </label>
            <label class="field-group app-play-interests__filter-field" for="account-subscription-status">
                <span>Статус</span>
                <span class="select-control">
                    <select id="account-subscription-status" name="status">
                        <option value="all">Все статусы</option>
                        <option value="active">Активные</option>
                        <option value="paused">На паузе</option>
                        <option value="expired">Истекшие</option>
                        <option value="cancelled">Отменённые</option>
                        <option value="completed">Выполненные</option>
                    </select>
                    <svg aria-hidden="true"><use href="#chevron-down"/></svg>
                </span>
            </label>
            <button type="reset" class="button secondary app-play-interests__filter-reset" data-subscriptions-reset>Сбросить</button>
        </form>
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
        <div class="app-play-interests__list" data-play-interests-list role="region" aria-live="polite">
            <p class="muted">Загружаем подписки…</p>
        </div>
        <nav class="app-play-interests__pagination" data-subscriptions-pagination aria-label="Страницы подписок" hidden></nav>
    </section>
@endsection
