{{-- Fixed, synthetic fixtures. No authenticated portal calls, no actual account data. --}}
@php
    $game = ['id' => 1, 'title' => 'Открытая игра 5×5', 'venue_name' => 'Баскетбольный зал на Яхромской', 'starts_at' => '2026-11-28T15:00:00Z', 'time_confirmed' => true, 'url' => '/events/1-demo', 'reason' => 'venue_interest'];
    $training = ['id' => 2, 'title' => 'Вечерняя тренировка', 'venue_name' => 'Спортивный центр', 'starts_at' => '2026-11-29T17:00:00Z', 'time_confirmed' => false, 'url' => '/events/2-demo', 'reason' => 'criteria_match'];
    $cards = [
        ['source' => 'my_interests', 'title' => 'Мои интересы', 'data' => ['total' => 3, 'items' => [$game, $training]]],
        ['source' => 'upcoming_events', 'title' => 'Моё расписание', 'data' => ['total' => 2, 'items' => [$game + ['role' => 'participant'], $training + ['role' => 'organizer']]]],
        ['source' => 'my_rental_requests', 'title' => 'Мои заявки на аренду', 'data' => ['total' => 2, 'items' => [
            ['id' => 'booking-demo', 'kind' => 'booking', 'venue_name' => 'Спортивный центр', 'status_label' => 'Ожидает подтверждения', 'starts_at' => '2026-12-01T16:00:00Z', 'url' => '/account/venue-bookings/booking-demo'],
            ['id' => 'pre-demo', 'kind' => 'pre_request', 'venue_name' => 'Зал № 2', 'status_label' => 'Активна', 'starts_at' => '2026-12-02T17:00:00Z', 'url' => '/account/venue-pre-requests/pre-demo'],
        ], 'summary' => ['booking_total' => 1, 'pre_request_total' => 1]]],
        ['source' => 'wallet_summary', 'title' => 'Мои финансы', 'data' => ['total' => 2, 'summary' => ['total_balance_minor' => 325000, 'real_balance_minor' => 275000, 'bonus_balance_minor' => 50000, 'currency' => 'RUB'], 'items' => [
            ['id' => 1, 'title' => 'Пополнение', 'completed_at' => '2026-11-25T12:00:00Z', 'total_delta_minor' => 150000],
            ['id' => 2, 'title' => 'Оплата', 'completed_at' => '2026-11-24T15:30:00Z', 'total_delta_minor' => -75000],
        ]]],
        ['source' => 'unread_messages', 'title' => 'Мои сообщения', 'data' => ['total' => 4, 'items' => []]],
    ];
    if ($state === 'overflow') {
        $cards[0]['data']['items'][0]['title'] = 'Открытая баскетбольная игра для участников любого уровня подготовки с предварительным распределением по командам';
        $cards[0]['data']['items'][0]['venue_name'] = 'Многофункциональный спортивный комплекс с несколькими игровыми залами';
    }
    if ($state === 'empty') {
        foreach ($cards as &$card) {
            $card['data'] = ['total' => 0, 'items' => []];
        }
        unset($card);
    }
    // A revoked source must disappear from the catalogue; retain only a transient
    // revoked placeholder to preview the response to a refresh that lost access.
    $contentState = in_array($state, ['normal', 'empty', 'overflow'], true) ? 'normal' : $state;
@endphp
@include('ui-studio.cases.dynamic-dashboard-candidate')
<main class="container app-account-overview" data-studio-dashboard>
    <h1>Обзор аккаунта</h1>
    <div class="app-dashboard-grid">
        @foreach ($cards as $card)
            <article class="panel app-dashboard-widget{{ $loop->last ? ' app-dashboard-widget--full' : '' }}">
                <div class="app-dashboard-widget__header"><h2>{{ $card['title'] }}</h2></div>
                <div class="app-dashboard-widget__content">
                    {!! \Illuminate\Support\Facades\View::file(resource_path('themes/mskba_app/views/pages/account/partials/dashboard-dynamic-content.blade.php'), [
                        'source' => $card['source'],
                        'data' => $card['data'],
                        'state' => $contentState,
                        'preview' => true,
                    ])->render() !!}
                </div>
            </article>
        @endforeach
    </div>
    <p role="status" data-studio-dashboard-notice></p>
</main>
<script>
document.querySelectorAll('[data-studio-dynamic-link]').forEach(button => {
    button.addEventListener('click', () => {
        document.querySelector('[data-studio-dashboard-notice]').textContent = 'Демо: переход «' + button.textContent.trim() + '»';
    });
});
document.querySelectorAll('[data-dynamic-retry]').forEach(button => {
    button.addEventListener('click', () => {
        document.querySelector('[data-studio-dashboard-notice]').textContent = 'Демо: повторный запрос (без обращения к порталу)';
    });
});
</script>
