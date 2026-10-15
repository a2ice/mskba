@extends('theme::layouts.account', ['title' => 'Сообщения — Операторский диалог', 'compactSidebar' => true])

@section('account-content')
<div class="app-message-workspace">
    @include('theme::pages.account.partials.conversation-rail')
    <section class="app-messages app-messages--thread" data-chat-root
        data-chat-history-url="{{ route('account.notifications.threads.operator.history', $conversation->id) }}"
        data-chat-read-url="{{ route('account.notifications.threads.operator.read', $conversation->id) }}"
        data-chat-operator-state-url="{{ route('account.notifications.threads.operator.state', $conversation->id) }}"
        data-chat-channel="operator-conversations.{{ $conversation->id }}"
        data-chat-unread-initial="{{ $unread }}" aria-label="Диалог с оператором">
        <header class="panel app-messages__thread-header">
            <a class="app-messages__back" href="{{ route('account.notifications') }}" aria-label="Все сообщения">
                <svg aria-hidden="true"><use href="#arrow"/></svg>
            </a>
            <span class="app-messages__avatar" aria-hidden="true"><svg><use href="#message"/></svg></span>
            <div class="app-messages__thread-contact">
                <h2>Операторский диалог — {{ $contact->username }}</h2>
                <p>Сервисное сообщение MSKBA</p>
            </div>
        </header>
        <div class="panel app-messages__chat-log" data-chat-scroll tabindex="0" role="region"
            aria-label="История операторского диалога">
            <div class="app-messages__load" data-chat-load hidden>
                <span class="spinner" aria-hidden="true"></span><span>Загружаем историю…</span>
            </div>
            <div class="app-messages__history" data-chat-history data-next-before="{{ $nextBefore }}"
                role="log" aria-live="polite">
                @foreach ($messages as $message)
                    @include('theme::pages.account.partials.chat-bubble', [
                        'entry' => [
                            'kind' => 'message',
                            'id' => 'message-'.$message->id,
                            'text' => $message->body,
                            'at' => $message->created_at,
                            'sender' => $message->sender,
                        ],
                        'viewer' => $viewer,
                    ])
                @endforeach
            </div>
        </div>
        <form class="panel app-messages__compose" data-chat-compose
            method="POST" action="{{ route('account.notifications.threads.operator.messages', $conversation->id) }}">
            @csrf
            <textarea name="body" rows="3" maxlength="2000" required
                aria-label="Сообщение" placeholder="Введите сообщение…" @disabled(! $canSend) {{ $canSend ? '' : 'hidden' }}></textarea>
            <p class="app-messages__direct-status" data-chat-operator-status role="status"
                {{ $canSend ? 'hidden' : '' }}>Отправка сообщений недоступна.</p>
            <div class="app-messages__compose-footer">
                <button class="button primary" type="submit" data-chat-send @disabled(! $canSend)>Отправить</button>
            </div>
            <p class="app-messages__read-error" data-chat-error role="alert" hidden></p>
        </form>
        <div data-conversation-read-url="{{ route('account.notifications.threads.operator.read', $conversation->id) }}"
            data-unread-count="{{ $unread }}"></div>
    </section>
</div>
@endsection
