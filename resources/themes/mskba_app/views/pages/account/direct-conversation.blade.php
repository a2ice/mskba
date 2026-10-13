@extends('theme::layouts.account', ['title' => 'Сообщения — Личная переписка с '.($recipient->username ?: 'участником'), 'compactSidebar' => true])

@section('account-content')
@php($contactName = $recipient->username ?: (trim(($recipient->profile?->first_name ?? '').' '.($recipient->profile?->last_name ?? '')) ?: 'Участник'))
<div class="app-message-workspace">
@include('theme::pages.account.partials.conversation-rail')
<section class="app-messages app-messages--thread" data-chat-root
    data-chat-history-url="{{ route('account.notifications.threads.direct.history', $conversation->id) }}"
    data-chat-channel="direct-conversations.{{ $conversation->id }}"
    data-chat-direct-state-url="{{ route('account.notifications.threads.direct.state', $conversation->id) }}"
    data-chat-read-url="{{ route('account.notifications.threads.direct.read', $conversation->id) }}"
    data-chat-unread-initial="{{ $unread }}" aria-label="Личная переписка">
    <header class="panel app-messages__thread-header">
        <a class="app-messages__back" href="{{ route('account.notifications') }}" aria-label="Все сообщения"><svg aria-hidden="true"><use href="#arrow"/></svg></a>
        <span class="app-messages__avatar" aria-hidden="true"><svg><use href="#message"/></svg></span>
        <div class="app-messages__thread-contact"><h2>{{ $contactName }}</h2><p>Личная переписка</p></div>
    </header>
    <div class="panel app-messages__chat-log" data-chat-scroll role="region" aria-label="Прокручиваемая история переписки" tabindex="0">
        <div class="app-messages__load" data-chat-load aria-live="polite" hidden>
            <span class="spinner" aria-hidden="true"></span><span>Загружаем историю…</span>
        </div>
        <div class="app-messages__history" data-chat-history data-next-before="{{ $nextBefore }}" role="log" aria-live="polite" aria-label="История переписки">
            @foreach ($messages as $message)
                @include('theme::pages.account.partials.direct-chat-entry', compact('message', 'viewer'))
            @endforeach
        </div>
    </div>
    <form class="panel app-messages__compose" action="{{ route('account.notifications.direct.send', $recipient->id) }}" method="POST" data-chat-compose>
        @csrf
        <label class="sr-only" for="direct-thread-message">Сообщение</label>
        <textarea id="direct-thread-message" name="body" rows="3" maxlength="200" required
            @disabled(!$canSend) {{ $canSend ? '' : 'hidden' }} placeholder="Введите сообщение…"></textarea>
        <p class="app-messages__direct-status" data-chat-direct-status role="status" aria-live="polite" {{ $canSend ? 'hidden' : '' }}>{{ $blockedByMe ? 'Вы заблокировали пользователя.' : 'Отправка личных сообщений недоступна.' }}</p>
        <div class="app-messages__compose-footer">
            <span class="app-messages__char-count" data-direct-char-count {{ $canSend ? '' : 'hidden' }}>0 / 200</span>
            <details class="app-context-actions app-messages__actions" data-chat-actions>
                @include('theme::pages.account.partials.chat-actions-trigger', ['ariaLabel' => 'Действия с перепиской'])
                <div class="app-context-actions__menu" aria-label="Действия с перепиской">
                    <button type="submit" form="direct-thread-unblock" data-direct-unblock-action {{ $blockedByMe ? '' : 'hidden' }}>Разблокировать пользователя</button>
                    <button type="submit" form="direct-thread-block" data-direct-block-action {{ $blockedByMe ? 'hidden' : '' }}>Заблокировать пользователя</button>
                    <a href="{{ route('account.notifications') }}">Все сообщения</a>
                    <a href="{{ route('account.settings') }}">Настройки приватности</a>
                </div>
            </details>
            <button class="button primary" type="submit" data-chat-send @disabled(!$canSend)>Отправить</button>
        </div>
        <p class="app-messages__read-error" data-chat-error role="alert" hidden></p>
    </form>
    <form id="direct-thread-unblock" method="POST" action="{{ route('account.notifications.direct.unblock', $recipient->id) }}" data-direct-block-form>
        @csrf @method('DELETE')
    </form>
    <form id="direct-thread-block" method="POST" action="{{ route('account.notifications.direct.block', $recipient->id) }}" data-direct-block-form>@csrf</form>
    <div data-conversation-read-url="{{ route('account.notifications.threads.direct.read', $conversation->id) }}" data-unread-count="{{ $unread }}"></div>
</section>
</div>
@endsection
