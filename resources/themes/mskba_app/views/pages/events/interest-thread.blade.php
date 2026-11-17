@php
    $event = $interest->event;
    $title = 'Предзаявка: '.$event->title;
    $timezone = $interest->venue->schedule()->value('timezone') ?: 'Europe/Moscow';
    $booking = $event->sourceBooking;
    $pending = $interest->proposals->where('status', 'pending');
    $currencyFormat = fn($amount, $currency) => number_format(($amount ?? 0) / 100, 2, ',', ' ').
        ' '.($currency === 'RUB' ? '₽' : $currency);
@endphp
@extends('theme::layouts.app', ['title' => $title])
@section('content')
<section class="app-account-section">
    <div class="container app-account-section__inner">
        <header class="app-account-section__heading">
            <p><a href="{{ route('events.show', $event->routeIdentifier()) }}">← К мероприятию</a>
                @unless($isOrganizer) · <a href="{{ route('account.venues.interests.index', $interest->venue) }}">Все предзаявки площадки</a>@endunless</p>
            <h1>Предварительный интерес к аренде</h1>
            <p>{{ $event->title }}</p>
        </header>
        @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if(session('error'))<p role="alert">{{ session('error') }}</p>@endif
        <section class="panel">
            <h2>{{ $interest->venue->name }}</h2>
            <p>{{ $interest->court?->name ?? 'Зал' }} ·
                {{ $interest->starts_at->setTimezone($timezone)->format('d.m.Y H:i') }}–{{ $interest->ends_at->setTimezone($timezone)->format('H:i') }}
                · версия {{ $interest->revision }}</p>
            <p><strong>Предзаявка ничего не резервирует и никого не обязывает.</strong>
                Площадка может быть занята до оформления настоящей брони.</p>
            <p>Состояние: {{ match($interest->status) {
                'open' => 'Предварительный интерес', 'booking_requested' => 'Заявка на аренду отправлена',
                'revised' => 'Изменена', 'withdrawn' => 'Отозвана', default => $interest->status,
            } }}</p>
            @if($booking)
                <p>Заявка на аренду: <strong>{{ $booking->status->label() }}</strong>.
                    @if($canViewBooking)<a href="{{ route('account.venue-bookings.show', $booking) }}">Открыть полную заявку</a>@endif
                </p>
                @if($canViewBooking)
                    <div class="app-rental-pricing__actions">
                        @foreach(['accept' => 'Принять', 'confirm' => 'Подтвердить', 'reject' => 'Отклонить', 'cancel' => 'Отменить'] as $action => $label)
                            @if($bookingActions[$action]['allowed'] ?? false)
                                <form method="POST" action="{{ route('account.venue-bookings.'.$action, $booking) }}">
                                    @csrf
                                    <input type="hidden" name="version" value="{{ $booking->optimistic_version }}">
                                    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                    <button type="submit" class="button {{ in_array($action, ['accept', 'confirm']) ? 'primary' : 'secondary' }}">{{ $label }} заявку</button>
                                </form>
                            @endif
                        @endforeach
                    </div>
                @endif
            @endif
        </section>
        @if($canAct)
            <section class="panel" aria-labelledby="interest-conversion-heading">
                <h2 id="interest-conversion-heading">Перейти к бронированию</h2>
                @if($isOrganizer)
                    <p>Оформить настоящую бронь можно сейчас или позже, с пересчётом тарифа и повторной проверкой свободного времени.</p>
                    <form method="POST" action="{{ route('events.booking.quote', $event->routeIdentifier()) }}">
                        @csrf
                        <input type="hidden" name="expected_version" value="{{ $event->participation_confirmation_version }}">
                        <input type="hidden" name="interest_id" value="{{ $interest->id }}">
                        <button type="submit" class="button primary">Создать заявку на бронь</button>
                    </form>
                @elseif($canPropose && $pending->isEmpty())
                    <p>Предложите организатору оформить заявку. Без его согласия аренда не создаётся.</p>
                    <form method="POST" action="{{ route('events.interests.proposals.store', [
                        'event' => $event->routeIdentifier(), 'interest' => $interest->id,
                    ]) }}">
                        @csrf<button type="submit" class="button primary">Предложить оформление брони</button>
                    </form>
                @endif
            </section>
        @endif
        @foreach($interest->proposals as $proposal)
            <section class="panel">
                <h2>Предложение №{{ $proposal->id }}</h2>
                <p>Инициатор: {{ $proposal->initiator?->user?->username ?? 'Представитель площадки' }}.
                    Ориентировочная цена: <strong>{{ $currencyFormat($proposal->quoted_amount_minor, $proposal->currency) }}</strong>.</p>
                <p>Статус: {{ match($proposal->status) {
                    'pending' => 'Ожидает ответа', 'accepted' => 'Принято',
                    'declined' => 'Отклонено', default => $proposal->status,
                } }}</p>
                @if($isOrganizer && $canAct && $proposal->status === 'pending')
                    <div class="app-rental-pricing__actions">
                        <form method="POST" action="{{ route('events.booking.quote', $event->routeIdentifier()) }}">
                            @csrf
                            <input type="hidden" name="expected_version" value="{{ $event->participation_confirmation_version }}">
                            <input type="hidden" name="interest_id" value="{{ $interest->id }}">
                            <input type="hidden" name="proposal_id" value="{{ $proposal->id }}">
                            <button type="submit" class="button primary">Посмотреть цену и принять</button>
                        </form>
                        <form method="POST" action="{{ route('events.interests.proposals.decline', [
                            'event' => $event->routeIdentifier(), 'interest' => $interest->id, 'proposal' => $proposal->id,
                        ]) }}">
                            @csrf<button type="submit" class="button secondary">Отклонить</button>
                        </form>
                    </div>
                @endif
            </section>
        @endforeach
        <section class="panel" aria-labelledby="interest-chat-heading">
            <h2 id="interest-chat-heading">Переписка</h2>
            <div id="interestMessages" data-feed="{{ route('events.interests.messages', [
                    'event' => $event->routeIdentifier(), 'interest' => $interest->id,
                ]) }}" data-conversation-id="{{ $conversation->public_id }}"
                aria-live="polite">
                @forelse($messages as $message)
                    <article data-interest-message-id="{{ $message->id }}">
                        <small>{{ $message->type === 'system' ? 'Событие' : ($message->authorActor?->user?->username ?? 'Пользователь') }}
                            · {{ $message->created_at->format('d.m.Y H:i') }}</small>
                        <p>{{ $message->body }}</p>
                    </article>
                @empty
                    <p id="interestMessagesEmpty">Сообщений пока нет.</p>
                @endforelse
            </div>
            <form method="POST" action="{{ route('events.interests.messages.store', [
                'event' => $event->routeIdentifier(), 'interest' => $interest->id,
            ]) }}">
                @csrf
                <input type="hidden" name="client_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <div class="field-group">
                    <label for="interestMessageBody">Сообщение</label>
                    <textarea id="interestMessageBody" name="body" maxlength="4000" rows="3" required></textarea>
                </div>
                <button type="submit" class="button primary">Отправить</button>
            </form>
        </section>
    </div>
</section>
@endsection
@push('scripts')
<script>
(() => {
  const panel = document.getElementById('interestMessages');
  if (!panel) return;
  let lastId = Number(panel.lastElementChild?.dataset?.interestMessageId || 0);
  let loading = false;
  const refresh = async () => {
    if (loading || document.hidden) return;
    loading = true;
    try {
      const response = await fetch(panel.dataset.feed + '?after_id=' + lastId, {
        credentials: 'same-origin', headers: { Accept: 'application/json' }
      });
      if (!response.ok) return;
      const payload = await response.json();
      for (const message of payload.messages || []) {
        if (message.id <= lastId) continue;
        const article = document.createElement('article');
        article.dataset.interestMessageId = message.id;
        const author = document.createElement('small');
        author.textContent = (message.type === 'system' ? 'Событие' : message.author) + ' · ' + message.created_at;
        const body = document.createElement('p');
        body.textContent = message.body || '';
        article.append(author, body);
        panel.appendChild(article);
        lastId = message.id;
        document.getElementById('interestMessagesEmpty')?.remove();
      }
    } catch (_) {} finally { loading = false; }
  };
  window.setInterval(refresh, 10000);
  if (window.Echo && panel.dataset.conversationId) {
    window.Echo.private('venue-booking-conversations.' + panel.dataset.conversationId)
      .listen('.interest.message.sent', refresh)
      .listen('.booking.message.sent', refresh);
  }
})();
</script>
@endpush
