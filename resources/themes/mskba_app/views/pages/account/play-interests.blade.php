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
        data-venues-url="{{ route('venues.search') }}"
        data-venue-subscriptions-url="{{ url('/account/venue-subscriptions') }}"
        data-channels-url="{{ route('subscriptions.channels') }}">
        <form class="app-play-interests__filters" data-subscriptions-filters aria-label="Фильтры подписок">
            <label class="field-group app-play-interests__filter-field" for="account-subscription-type">
                <span>Тип подписки</span>
                <span class="select-control">
                    <select id="account-subscription-type" name="type">
                        <option value="all">Все типы</option>
                        <option value="play">Без конкретной площадки</option>
                        <option value="venue">С привязкой к площадке</option>
                    </select>
                    <svg aria-hidden="true"><use href="#chevron-down"/></svg>
                </span>
            </label>
            <label class="field-group app-play-interests__filter-field" for="account-subscription-status">
                <span>Статус</span>
                <span class="select-control">
                    <select id="account-subscription-status" name="status">
                        <option value="all">Все статусы</option>
                        <option value="active" selected>Активные</option>
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
                    'channels' => route('subscriptions.channels'),
                ],
            ];
        @endphp
        <div data-mskba-play-intent-root data-options='@json($interestDialogOptions)'></div>
        <div class="app-play-interests__list" data-play-interests-list role="region" aria-live="polite">
            <p class="muted">Загружаем подписки…</p>
        </div>
        <nav class="app-play-interests__pagination" data-subscriptions-pagination aria-label="Страницы подписок" hidden></nav>
        <dialog class="mskba-modal app-subscription-details" data-subscription-details-dialog aria-labelledby="subscription-details-title">
            <div class="app-subscription-details__shell">
                <header class="app-subscription-details__header">
                    <div class="app-subscription-details__heading">
                        <span class="app-subscription-details__eyebrow">MSKBA / ПОДПИСКИ</span>
                        <h2 id="subscription-details-title" data-subscription-details-title>Подписка</h2>
                    </div>
                    <button type="button" class="app-subscription-details__close" data-subscription-details-close aria-label="Закрыть">
                        <svg aria-hidden="true"><use href="#close"/></svg>
                    </button>
                </header>
                <div class="app-subscription-details__content">
                    <h3>Параметры подписки</h3>
                    <dl class="app-subscription-details__body" data-subscription-details-body></dl>
                    <p class="error app-subscription-details__error" data-subscription-details-error role="alert" hidden></p>
                </div>
                <footer class="app-subscription-details__footer">
                    <button type="button" class="button secondary" data-subscription-details-close>Закрыть</button>
                    <button type="button" class="button primary" data-subscription-details-edit hidden>Редактировать</button>
                    <button type="button" class="button primary" data-subscription-details-restore hidden>Восстановить подписку</button>
                </footer>
            </div>
        </dialog>
    </section>
    @php
        $telegramBotUsername = ltrim(trim((string) config('telegram.bot_username')), '@');
        $vkEnabled = trim((string) config('vk.app_id')) !== '';
        $contactEmulationEnabled = \App\Modules\Contact\Presentation\Http\Controllers\EmulateSocialContactController::enabled();
    @endphp
    <div class="app-subscription-contact-dialog">
        @include('theme::pages.account.partials.contact-add-dialog')
    </div>
@endsection
