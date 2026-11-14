@php
    use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
    $event = $interest->event;
    $title = 'Переписка по предзаявке';
    $timezone = $interest->venue->schedule()->value('timezone') ?: 'Europe/Moscow';
    $booking = $event->sourceBooking;
    $bookingStatus = $booking?->status?->label();
    $pendingProposals = $interest->proposals->where('status', 'pending');
    $quoteTotal = fn($minor, $currency) => number_format(($minor ?? 0) / 100, 2, ',', ' ').
        ' '.($currency === 'RUB' ? '₽' : $currency);
@endphp
@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'events',
    'sectionClass' => 'events-section',
    'contentTitle' => $title,
    'contentSubtitle' => $event->title,
    'sidebarLabel' => 'Предварительная аренда',
])
@section('section-sidebar')
    <div class="section-sidebar-block">
        <a class="nav-link" href="{{ route('events.show', $event->routeIdentifier()) }}">Мероприятие</a>
        @if(!$isOrganizer)
            <a class="nav-link" href="{{ route('account.venues.interests.index', $interest->venue) }}">Все предзаявки площадки</a>
        @endif
    </div>
@endsection
@section('section-content')
    @if(session('status'))<div class="alert alert-success mb-3">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger mb-3">{{ session('error') }}</div>@endif
    <div class="alert alert-info mb-3">
        Предзаявка — информационный интерес к выбранному времени, не бронь и не обязательство.
        Переписка сохраняется при создании заявки и последующих решениях.
    </div>
    <article class="card mb-3"><div class="card-body">
        <h2 class="h5 mb-2">{{ $interest->venue->name }} · {{ $interest->court?->name ?? 'Зал' }}</h2>
        <p class="mb-2">
            {{ $interest->starts_at->setTimezone($timezone)->format('d.m.Y H:i') }} —
            {{ $interest->ends_at->setTimezone($timezone)->format('H:i') }}
            · версия {{ $interest->revision }}
        </p>
        <p class="mb-0">Состояние: <strong>{{ match($interest->status) {
            'open' => 'Предварительный интерес',
            'booking_requested' => 'Заявка отправлена',
            'revised' => 'Заменена новой версией',
            'withdrawn' => 'Отозвана',
            default => $interest->status,
        } }}</strong></p>
        @if($booking)
            <p class="mt-2 mb-0">Заявка на бронь: <strong>{{ $bookingStatus }}</strong>.
                @if($canViewBooking)
                    <a class="fc-link" href="{{ route('account.venue-bookings.show', $booking) }}">Открыть полную заявку</a>
                @endif
            </p>
        @endif
        @if($booking && $canViewBooking)
            <div class="d-flex flex-wrap gap-2 mt-3">
                @foreach(['accept' => 'Принять', 'confirm' => 'Подтвердить', 'reject' => 'Отклонить', 'cancel' => 'Отменить'] as $action => $caption)
                    @if($bookingActions[$action]['allowed'] ?? false)
                        <form method="POST" action="{{ route('account.venue-bookings.'.$action, $booking) }}">
                            @csrf
                            <input type="hidden" name="version" value="{{ $booking->optimistic_version }}">
                            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <button type="submit" class="btn btn--{{ in_array($action, ['accept', 'confirm']) ? 'primary' : 'secondary' }} btn--sm">{{ $caption }} заявку</button>
                        </form>
                    @endif
                @endforeach
            </div>
            <small class="text-muted mt-2 d-block">Действия применяют стандартные проверки и правила аренды.</small>
        @endif
    </div></article>

    @if($canAct)
        <article class="card mb-3"><div class="card-body">
            <h2 class="h5">Переход к бронированию</h2>
            @if($isOrganizer)
                <p>Вы можете самостоятельно оформить бронь в любое время. Система повторно проверит свободный интервал и рассчитает окончательную цену.</p>
                <form method="POST" action="{{ route('events.booking.quote', $event->routeIdentifier()) }}">
                    @csrf
                    <input type="hidden" name="expected_version" value="{{ $event->participation_confirmation_version }}">
                    <input type="hidden" name="interest_id" value="{{ $interest->id }}">
                    <button type="submit" class="btn btn--primary">Создать заявку на бронь</button>
                </form>
            @elseif($canPropose && $pendingProposals->isEmpty())
                <p>Подготовьте клиенту предложение по актуальному времени и тарифу. Бронь появится только после согласия организатора.</p>
                <form method="POST" action="{{ route('events.interests.proposals.store', ['event' => $event->routeIdentifier(), 'interest' => $interest->id]) }}">
                    @csrf
                    <button type="submit" class="btn btn--primary">Предложить клиенту оформить бронь</button>
                </form>
            @endif
        </div></article>
    @endif

    @foreach($interest->proposals as $proposal)
        <article class="card mb-3"><div class="card-body">
            <h2 class="h6">Предложение №{{ $proposal->id }}</h2>
            <p class="mb-2">Инициатор:
                {{ $proposal->initiator?->user?->username ?? 'Представитель площадки' }}.
                Ориентировочно: <strong>{{ $quoteTotal($proposal->quoted_amount_minor, $proposal->currency) }}</strong>.
                <span class="text-muted">Сумма уточняется перед бронированием.</span>
            </p>
            <p class="mb-2">Статус: {{ match($proposal->status) {
                'pending' => 'Ожидает решения организатора',
                'accepted' => 'Принято, заявка создана',
                'declined' => 'Отклонено',
                default => $proposal->status,
            } }}</p>
            @if($isOrganizer && $canAct && $proposal->status === 'pending')
                <div class="d-flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('events.booking.quote', $event->routeIdentifier()) }}">
                        @csrf
                        <input type="hidden" name="expected_version" value="{{ $event->participation_confirmation_version }}">
                        <input type="hidden" name="interest_id" value="{{ $interest->id }}">
                        <input type="hidden" name="proposal_id" value="{{ $proposal->id }}">
                        <button type="submit" class="btn btn--primary btn--sm">Посмотреть окончательную цену и принять</button>
                    </form>
                    <form method="POST" action="{{ route('events.interests.proposals.decline', [
                        'event' => $event->routeIdentifier(), 'interest' => $interest->id, 'proposal' => $proposal->id,
                    ]) }}">
                        @csrf
                        <button type="submit" class="btn btn--secondary btn--sm">Отклонить</button>
                    </form>
                </div>
            @endif
        </div></article>
    @endforeach

    <section class="card mb-4">
        <div class="card-body">
            <h2 class="h5 mb-3">Переписка</h2>
            <div id="interestMessages"
                data-feed="{{ route('events.interests.messages', ['event' => $event->routeIdentifier(), 'interest' => $interest->id]) }}"
                data-conversation-id="{{ $conversation->public_id }}"
                style="max-height:420px;overflow-y:auto" aria-live="polite">
                @foreach($messages as $message)
                    <article class="border-bottom py-2" data-interest-message-id="{{ $message->id }}">
                        <small class="text-muted">
                            {{ $message->type === 'system' ? 'Событие' : ($message->authorActor?->user?->username ?? 'Пользователь') }}
                            · {{ $message->created_at->format('d.m.Y H:i') }}
                        </small>
                        <p class="mb-1">{{ $message->body }}</p>
                    </article>
                @endforeach
            </div>
            @if($messages->isEmpty())<p class="text-muted" id="interestMessagesEmpty">Сообщений пока нет.</p>@endif
            <form method="POST" action="{{ route('events.interests.messages.store', [
                'event' => $event->routeIdentifier(), 'interest' => $interest->id,
            ]) }}" class="mt-3">
                @csrf
                <input type="hidden" name="client_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <label for="interestMessageBody" class="form-label">Сообщение</label>
                <textarea id="interestMessageBody" class="form-control mb-2" rows="3" name="body" maxlength="4000" required></textarea>
                <button type="submit" class="btn btn--primary btn--sm">Отправить</button>
            </form>
        </div>
    </section>

    <script>
    (() => {
      const panel = document.getElementById('interestMessages');
      if (!panel) return;
      let lastId = Number(panel.lastElementChild?.dataset?.interestMessageId || 0);
      let running = false;
      const refresh = async () => {
        if (running || document.hidden) return;
        running = true;
        try {
          const response = await fetch(panel.dataset.feed + '?after_id=' + lastId, {
            credentials: 'same-origin', headers: { Accept: 'application/json' }
          });
          if (!response.ok) return;
          const payload = await response.json();
          for (const message of payload.messages || []) {
            if (message.id <= lastId) continue;
            const article = document.createElement('article');
            article.className = 'border-bottom py-2';
            article.dataset.interestMessageId = message.id;
            const header = document.createElement('small');
            header.className = 'text-muted';
            header.textContent = (message.type === 'system' ? 'Событие' : message.author) + ' · ' + message.created_at;
            const body = document.createElement('p');
            body.className = 'mb-1';
            body.textContent = message.body || '';
            article.append(header, body);
            panel.appendChild(article);
            lastId = message.id;
            document.getElementById('interestMessagesEmpty')?.remove();
            panel.scrollTop = panel.scrollHeight;
          }
        } catch (_) { /* Next scheduled refresh recovers transient errors. */ }
        finally { running = false; }
      };
      window.setInterval(refresh, 10000);
      if (window.Echo && panel.dataset.conversationId) {
        window.Echo.private('venue-booking-conversations.' + panel.dataset.conversationId)
          .listen('.interest.message.sent', refresh)
          .listen('.booking.message.sent', refresh);
      }
    })();
    </script>
@endsection
