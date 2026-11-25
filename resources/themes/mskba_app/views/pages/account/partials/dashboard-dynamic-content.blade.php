{{-- Presentation-only candidate for #1073. Not included by the live dashboard until UI acceptance.
    Contract: $source, $data (DashboardWidgetData::toArray()), $state, $preview. --}}
@php
    $state = $state ?? 'normal';
    $preview = $preview ?? false;
    // Never retain previously fetched items in restricted/loading/error states.
    $items = $state === 'normal' ? ($data['items'] ?? []) : [];
    $summary = $state === 'normal' ? ($data['summary'] ?? []) : [];
    $total = $state === 'normal' ? (int) ($data['total'] ?? 0) : 0;
    $emptyText = match ($source) {
        'my_interests' => 'Пока нет предстоящих мероприятий по твоим интересам.',
        'upcoming_events' => 'В расписании пока нет предстоящих мероприятий.',
        'my_rental_requests' => 'Активных заявок и предзаявок пока нет.',
        'wallet_summary' => 'Операций пока нет.',
        default => 'Новых сообщений нет.',
    };
    $mainLink = match ($source) {
        'my_interests' => ['/account/subscriptions', 'Мои подписки'],
        'upcoming_events' => ['/account/my-games?scope=playing', 'Мои игры'],
        'my_rental_requests' => [$summary['bookings_url'] ?? '/account/venue-bookings', 'Мои заявки'],
        'wallet_summary' => [$summary['url'] ?? '/account/wallet', 'Кошелёк'],
        'unread_messages' => ['/account/notifications', 'Сообщения'],
        default => null,
    };
    $money = static fn (int $minor): string => number_format($minor / 100, 2, ',', ' ').' ₽';
    $date = static fn (?string $value): string => $value
        ? \Carbon\CarbonImmutable::parse($value)->setTimezone(config('app.timezone', 'Europe/Moscow'))->format('d.m.Y, H:i')
        : '';
    $validLink = static fn ($url): bool => is_string($url) && str_starts_with($url, '/')
        && \App\Modules\Dashboard\Application\Rules\DashboardWidgetLinkRule::isValid($url);
@endphp
<div class="dashboard-dynamic" data-dynamic-source="{{ $source }}" aria-busy="{{ $state === 'loading' ? 'true' : 'false' }}">
    @if ($state === 'loading')
        <div class="dashboard-dynamic__skeleton" role="status"><span></span><span></span><span></span><p>Загрузка данных…</p></div>
    @elseif ($state === 'error')
        <p role="status">Не удалось обновить данные.</p>
        <button type="button" class="button secondary" data-dynamic-retry>Повторить</button>
    @elseif ($state === 'restricted')
        <p role="status">Данные больше недоступны.</p>
    @else
        @if ($source === 'wallet_summary')
            <div class="dashboard-dynamic__balance">
                <strong>{{ $money((int) ($summary['total_balance_minor'] ?? 0)) }}</strong>
                <div class="dashboard-dynamic__split">
                    <div><span>Основной баланс</span><b>{{ $money((int) ($summary['real_balance_minor'] ?? 0)) }}</b></div>
                    <div><span>Бонусный баланс</span><b>{{ $money((int) ($summary['bonus_balance_minor'] ?? 0)) }}</b></div>
                </div>
            </div>
        @elseif ($source === 'unread_messages')
            <div class="dashboard-dynamic__balance"><strong>{{ $total }}</strong><p>Непрочитанные сообщения и уведомления</p></div>
        @else
            <div class="dashboard-dynamic__count"><strong>{{ $total }}</strong><span>{{ match ($source) {
                'my_interests' => 'совпадений на ближайшие 30 дней',
                'upcoming_events' => 'мероприятий в расписании',
                'my_rental_requests' => 'заявок и предзаявок',
                default => 'записей',
            } }}</span></div>
        @endif
        @if ($source !== 'unread_messages' && $items !== [])
            <ul class="dashboard-dynamic__list">
                @foreach ($items as $item)
                    @php
                        $title = $source === 'my_rental_requests'
                            ? ($item['venue_name'] ?? 'Площадка')
                            : ($item['title'] ?? 'Мероприятие');
                        $url = $item['url'] ?? null;
                        $detail = match ($source) {
                            'my_interests' => ($item['venue_name'] ?? '').' · '.($item['reason'] === 'criteria_match' ? 'По условиям' : 'По площадке'),
                            'upcoming_events' => ($item['venue_name'] ?? '').' · '.(($item['role'] ?? '') === 'organizer' ? 'Организатор' : 'Участвую'),
                            'my_rental_requests' => (($item['kind'] ?? '') === 'pre_request' ? 'Предзаявка' : 'Заявка').' · '.($item['status_label'] ?? ''),
                            'wallet_summary' => $date($item['completed_at'] ?? null),
                            default => '',
                        };
                        $time = $date($item['starts_at'] ?? null);
                    @endphp
                    <li class="dashboard-dynamic__item">
                        <div class="dashboard-dynamic__item-text">
                            @if ($validLink($url))
                                @if ($preview)
                                    <button type="button" class="dashboard-dynamic__link" data-studio-dynamic-link>{{ $title }}</button>
                                @else
                                    <a class="dashboard-dynamic__link" href="{{ $url }}">{{ $title }}</a>
                                @endif
                            @else
                                <strong>{{ $title }}</strong>
                            @endif
                            <p>{{ $detail }}</p>
                            @if ($time !== '')
                                <p>{{ $time }}@if (array_key_exists('time_confirmed', $item) && ! $item['time_confirmed']) · Время предварительное@endif</p>
                            @endif
                        </div>
                        @if ($source === 'wallet_summary')
                            @php($delta = (int) ($item['total_delta_minor'] ?? 0))
                            <b class="dashboard-dynamic__delta {{ $delta > 0 ? 'is-positive' : '' }}">{{ $delta > 0 ? '+' : '' }}{{ $money($delta) }}</b>
                        @endif
                    </li>
                @endforeach
            </ul>
        @elseif ($source !== 'unread_messages' || $total === 0)
            <p>{{ $emptyText }}</p>
        @endif
        @if ($mainLink && $validLink($mainLink[0]))
            <div class="app-dashboard-widget__actions">
                @if ($preview)
                    <button class="button secondary" type="button" data-studio-dynamic-link>{{ $mainLink[1] }}</button>
                @else
                    <a class="button secondary" href="{{ $mainLink[0] }}">{{ $mainLink[1] }}</a>
                @endif
                @if ($source === 'my_rental_requests')
                    @php($preUrl = $summary['pre_requests_url'] ?? '/account/venue-pre-requests')
                    @if ($validLink($preUrl))
                        @if ($preview)
                            <button class="button secondary" type="button" data-studio-dynamic-link>Предзаявки</button>
                        @else
                            <a class="button secondary" href="{{ $preUrl }}">Предзаявки</a>
                        @endif
                    @endif
                @endif
            </div>
        @endif
    @endif
</div>
