@extends('theme::layouts.account', ['title' => 'Переписка по аренде', 'compactSidebar' => true])

@section('account-content')
@php
    $isPreRequest = $pre !== null;
    $venue = $isPreRequest ? $pre->venue : $booking->venue;
    $startsAt = $isPreRequest ? $pre->starts_at : $booking->starts_at;
    $endsAt = $isPreRequest ? $pre->ends_at : $booking->ends_at;
    $bookingId = $isPreRequest ? null : $booking->public_id;
    $messageUrl = $isPreRequest
        ? route('account.notifications.threads.pre-request.messages', $pre)
        : route('account.venue-bookings.conversation.index', $booking);
    $readUrl = $isPreRequest
        ? route('account.notifications.threads.pre-request.read', $pre)
        : route('account.notifications.threads.booking.read', $booking);
    $sendUrl = $isPreRequest
        ? route('account.venue-pre-requests.send', $pre)
        : route('account.venue-bookings.conversation.store', $booking);
    $attachmentBase = $isPreRequest
        ? url('/account/venue-pre-requests/'.$pre->public_id.'/files')
        : url('/account/venue-bookings/'.$booking->public_id.'/conversation/messages');
@endphp
<div class="app-message-workspace" data-rental-chat-root
     data-rental-context="{{ $isPreRequest ? 'pre-request' : 'booking' }}"
     data-rental-messages-url="{{ $messageUrl }}"
     data-rental-read-url="{{ $readUrl }}"
     data-rental-attachment-base="{{ $attachmentBase }}"
     data-rental-status-url="{{ $isPreRequest ? '' : route('account.venue-bookings.show', $booking) }}"
     data-rental-events-url="{{ $booking && config('features.venue_rental.portal') ? route('account.venue-bookings.timeline', $booking) : '' }}"
     data-rental-attach-url="{{ $isPreRequest ? '' : route('account.venue-bookings.conversation.attach', $booking) }}"
     data-rental-booking-id="{{ $bookingId }}"
     data-rental-conversation-id="{{ $conversation?->public_id }}"
     @if($isPreRequest)
         data-rental-pre-offer-url="{{ route('account.venue-pre-requests.offer', $pre) }}"
         data-rental-pre-respond-url="{{ route('account.venue-pre-requests.respond', $pre) }}"
         data-rental-pre-withdraw-url="{{ route('account.venue-pre-requests.withdraw', $pre) }}"
     @endif>
    @include('theme::pages.account.partials.conversation-rail')
    <section class="app-messages app-messages--thread" aria-label="Переписка по аренде">
        <header class="panel app-messages__thread-header">
            <a class="app-messages__back" href="{{ route('account.notifications') }}" aria-label="Все сообщения">
                <svg aria-hidden="true"><use href="#arrow"/></svg>
            </a>
            <span class="app-messages__avatar" aria-hidden="true"><svg><use href="#message"/></svg></span>
            <div class="app-messages__thread-contact">
                <h2>{{ $isPreRequest ? 'Предзаявка' : 'Бронирование' }} · {{ $venue?->name ?? 'Площадка' }}</h2>
                <p>
                    {{ $startsAt?->format('d.m.Y H:i') }}–{{ $endsAt?->format('H:i') }}
                    · <span data-rental-status-label>{{ $statusLabel }}</span>
                </p>
            </div>
            <a class="app-messages__thread-source" target="_blank" rel="noopener noreferrer"
               href="{{ $isPreRequest ? route('account.venue-pre-requests.show', $pre) : route('account.venue-bookings.show', $booking) }}">
                К заявке
            </a>
        </header>
        <div class="panel app-messages__chat-log" data-rental-chat-scroll tabindex="0" role="region" aria-label="История переписки">
            <button type="button" class="app-messages__load-older" data-rental-load-older @unless($hasMore) hidden @endunless>
                Показать предыдущие сообщения
            </button>
            <div class="app-messages__history" data-rental-chat-history role="log" aria-live="polite">
                @php
                    // Status transitions are part of the same chronological display,
                    // but remain in their original booking audit table.
                    $bookingEvents = $booking && config('features.venue_rental.portal')
                        ? $booking->transitions()->latest('booking_version')->limit(30)->get()->reverse()->values()
                        : collect();
                    $timeline = $messages->map(fn ($item) => ['kind' => 'message', 'at' => $item->created_at, 'entry' => $item])
                        ->concat($bookingEvents->map(fn ($event) => ['kind' => 'event', 'at' => $event->created_at, 'entry' => $event]))
                        ->sortBy('at')->values();
                @endphp
                @foreach($timeline as $item)
                    @if($item['kind'] === 'event')
                        @php($transition = $item['entry'])
                        @include('theme::pages.account.partials.booking-chat-event')
                    @else
                        @php($message = $item['entry'])
                        @include('theme::pages.account.partials.booking-chat-bubble')
                    @endif
                @endforeach
            </div>
        </div>
        <form class="panel app-messages__compose" data-rental-chat-compose action="{{ $sendUrl }}"
              method="POST" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="client_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <textarea name="body" rows="3" maxlength="4000" aria-label="Сообщение по заявке"
                      placeholder="Введите сообщение…" @disabled(!$canWrite) @if(!$canWrite) hidden @endif></textarea>
            <p class="app-messages__team-closed" data-rental-closed @if($canWrite) hidden @endif>
                Просмотр переписки доступен, отправка сообщений запрещена.
            </p>
            <div class="app-messages__compose-footer">
                @if($canWrite)
                    <label class="app-messages__rental-attach" title="Прикрепить файл">
                        <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.txt" data-rental-file>
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m21.4 11.6-9.2 9.2a6 6 0 0 1-8.5-8.5l10-10a4 4 0 0 1 5.7 5.7l-10 10a2 2 0 0 1-2.8-2.8l9.2-9.2"/></svg>
                        <span class="sr-only">Прикрепить файл</span>
                    </label>
                    <span class="app-messages__rental-file-name" data-rental-file-name></span>
                @endif
                <div class="app-messages__rental-actions" data-rental-actions>
                    <details class="app-context-actions app-messages__actions" data-chat-actions>
                        @include('theme::pages.account.partials.chat-actions-trigger', ['ariaLabel' => 'Действия по аренде'])
                        <div class="app-context-actions__menu" aria-label="Действия по аренде">
                            @if(!$isPreRequest)
                                <button type="button" data-rental-action="accept" hidden>Принять заявку</button>
                                <button type="button" data-rental-action="confirm" hidden>Подтвердить бронирование</button>
                                <button type="button" data-rental-action="reject" hidden>Отклонить заявку</button>
                                <button type="button" data-rental-action="cancel" hidden>Отменить бронирование</button>
                            @else
                                <button type="button" data-rental-action="offer" hidden>Предложить бронь</button>
                                <button type="button" data-rental-action="accept_offer" hidden>Принять предложение</button>
                                <button type="button" data-rental-action="decline_offer" hidden>Отклонить предложение</button>
                                <button type="button" data-rental-action="withdraw" hidden>Отозвать предзаявку</button>
                            @endif
                            <a href="{{ $isPreRequest ? route('account.venue-pre-requests.show', $pre) : route('account.venue-bookings.show', $booking) }}">
                                Открыть заявку
                            </a>
                            @if($isPreRequest && $booking)
                                <a data-rental-booking-link href="{{ route('account.venue-bookings.show', $booking) }}">К бронированию</a>
                            @elseif($isPreRequest)
                                <a data-rental-booking-link href="#" hidden>К бронированию</a>
                            @endif
                        </div>
                    </details>
                    <button class="button primary" type="button" data-rental-primary hidden></button>
                </div>
                <button type="submit" class="button primary" data-rental-send @if(!$canWrite) hidden @endif>Отправить</button>
            </div>
            <p class="app-messages__read-error" data-rental-error role="alert" hidden></p>
        </form>
        <dialog class="app-messages__review-dialog app-messages__team-review-dialog" data-rental-review-dialog>
            <form method="dialog">
                <h3 data-rental-dialog-title>Подтвердить действие</h3>
                <label for="rental-reason" data-rental-reason-label>Причина решения</label>
                <textarea id="rental-reason" name="reason" maxlength="2000" data-rental-reason></textarea>
                <div class="app-messages__team-review-actions">
                    <button type="button" class="button secondary" data-rental-dialog-cancel>Отмена</button>
                    <button type="button" class="button primary" data-rental-dialog-confirm>Продолжить</button>
                </div>
            </form>
        </dialog>
    </section>
</div>
@endsection
