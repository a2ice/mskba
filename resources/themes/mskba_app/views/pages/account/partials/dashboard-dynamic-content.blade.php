@php
    // Only visible, targeted widgets reach this partial. The registry repeats
    // domain authorization before reading anything personal.
    $data = null;
    try {
        $data = app(\App\Modules\Dashboard\Application\Services\DashboardDataRegistry::class)
            ->getData($widget, $dashboardUser)->toArray();
    } catch (\Throwable $exception) {
        if (! $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
            || ! in_array($exception->getStatusCode(), [403, 404], true)) {
            report($exception);
        }
    }
    $money = static fn (int $minor): string => number_format($minor / 100, 2, ',', ' ').' ₽';
    $date = static fn (?string $value): string => $value
        ? \Carbon\CarbonImmutable::parse($value)->setTimezone(config('app.timezone', 'Europe/Moscow'))->format('d.m.Y, H:i')
        : '';
    $validLink = static fn ($url): bool => is_string($url) && str_starts_with($url, '/')
        && \App\Modules\Dashboard\Application\Rules\DashboardWidgetLinkRule::isValid($url);
    $links = match ($source) {
        'my_interests' => [['/account/subscriptions', 'Мои подписки']],
        'upcoming_events' => [['/account/my-games?scope=playing', 'Мои игры']],
        'my_rental_requests' => [['/account/venue-bookings', 'Мои заявки'], ['/account/venue-pre-requests', 'Предзаявки']],
        'wallet_summary' => [['/account/wallet', 'Кошелёк']],
        'unread_messages' => [['/account/notifications', 'Сообщения']],
        default => [],
    };
@endphp
@if ($data === null)
    <p role="status">Не удалось получить данные виджета.</p>
@else
    @php
        $total = (int) $data['total'];
        $items = $data['items'];
        $summary = $data['summary'] ?? [];
        $emptyText = match ($source) {
            'my_interests' => 'Пока нет предстоящих мероприятий по твоим интересам.',
            'upcoming_events' => 'В расписании пока нет предстоящих мероприятий.',
            'my_rental_requests' => 'Активных заявок и предзаявок пока нет.',
            'wallet_summary' => 'Операций пока нет.',
            'needs_attention' => 'Сейчас нет действий, требующих твоего внимания.',
            default => 'Новых сообщений нет.',
        };
    @endphp
    <div class="app-dashboard-widget__richtext" data-dashboard-dynamic-source="{{ $source }}">
        @switch ($source)
            @case('wallet_summary')
                <p>Общий баланс: <strong>{{ $money((int) ($summary['total_balance_minor'] ?? 0)) }}</strong></p>
                <p>Основной: <strong>{{ $money((int) ($summary['real_balance_minor'] ?? 0)) }}</strong><br>
                    Бонусный: <strong>{{ $money((int) ($summary['bonus_balance_minor'] ?? 0)) }}</strong></p>
                @break
            @case('unread_messages')
                <p>Непрочитанных сообщений и уведомлений: <strong>{{ $total }}</strong></p>
                @break
            @case('my_interests')
                <p>Совпадений на ближайшие 30 дней: <strong>{{ $total }}</strong></p>
                @break
            @case('upcoming_events')
                <p>Мероприятий в расписании: <strong>{{ $total }}</strong></p>
                @break
            @case('needs_attention')
                <p>Требуют внимания: <strong>{{ $total }}</strong></p>
                @break
            @case('my_rental_requests')
                <p>Заявок: <strong>{{ (int) ($summary['booking_total'] ?? 0) }}</strong> · Предзаявок: <strong>{{ (int) ($summary['pre_request_total'] ?? 0) }}</strong></p>
                @break
        @endswitch
        @if ($source !== 'unread_messages' && $items !== [])
            <ul>
                @foreach ($items as $item)
                    @php
                        $title = $source === 'my_rental_requests' ? ($item['venue_name'] ?? 'Площадка') : ($item['title'] ?? 'Мероприятие');
                        $url = $item['url'] ?? null;
                        $detail = match ($source) {
                            'my_interests' => ($item['venue_name'] ?? '').' · '.(($item['reason'] ?? '') === 'criteria_match' ? 'По условиям' : 'По площадке'),
                            'upcoming_events' => ($item['venue_name'] ?? '').' · '.(($item['role'] ?? '') === 'organizer' ? 'Организатор' : 'Участвую'),
                            'my_rental_requests' => (($item['kind'] ?? '') === 'pre_request' ? 'Предзаявка' : 'Заявка').' · '.($item['status_label'] ?? ''),
                            'wallet_summary' => $date($item['completed_at'] ?? null),
                            'needs_attention' => ($item['description'] ?? '').(isset($item['deadline_at']) ? ' · Ответить до '.$date($item['deadline_at']) : ''),
                            default => '',
                        };
                        $time = $date($item['starts_at'] ?? null);
                    @endphp
                    <li>
                        @if ($validLink($url))
                            <a href="{{ $url }}">{{ $title }}</a>
                        @else
                            <strong>{{ $title }}</strong>
                        @endif
                        @if ($source === 'wallet_summary')
                            @php($delta = (int) ($item['total_delta_minor'] ?? 0))
                            · <strong>{{ $delta > 0 ? '+' : '' }}{{ $money($delta) }}</strong>
                        @endif
                        @if ($detail !== '')<br>{{ $detail }}@endif
                        @if ($time !== '')
                            <br>{{ $time }}@if (array_key_exists('time_confirmed', $item) && ! $item['time_confirmed']) · Время предварительное@endif
                        @endif
                    </li>
                @endforeach
            </ul>
        @elseif ($source !== 'unread_messages' || $total === 0)
            <p>{{ $emptyText }}</p>
        @endif
    </div>
    @if (empty($widget->actions) && $links !== [])
        <div class="app-dashboard-widget__actions">
            @foreach ($links as [$url, $label])
                @if ($validLink($url))<a class="button secondary" href="{{ $url }}">{{ $label }}</a>@endif
            @endforeach
        </div>
    @endif
@endif
