@extends('theme::layouts.account', ['title' => 'Сообщения — Техподдержка №'.$ticket->id, 'compactSidebar' => true])

@section('account-content')
<div class="app-message-workspace">
    @include('theme::pages.account.partials.conversation-rail')
    <section class="app-messages app-messages--thread" aria-label="Переписка с техподдержкой"
             data-chat-root data-chat-history-url="{{ route('account.notifications.threads.support.history', $ticket) }}"
             data-chat-read-url="{{ route('account.notifications.threads.support.read', $ticket) }}"
             data-chat-unread-initial="{{ $unread }}" data-chat-channel="support-tickets.{{ $thread->id }}">
        <header class="panel app-messages__thread-header">
            <a class="app-messages__back" href="{{ route('account.notifications') }}" aria-label="Все сообщения">
                <svg aria-hidden="true"><use href="#arrow"/></svg>
            </a>
            <span class="app-messages__avatar" aria-hidden="true"><svg><use href="#message"/></svg></span>
            <div class="app-messages__thread-contact">
                <h2>Техподдержка №{{ $ticket->id }} — {{ $ticket->subject }}</h2>
                <p>{{ $ticket->status === 'open' ? 'Открыто' : 'Завершено' }} · Главный: {{ $thread->lead?->username ?? 'не назначен' }}</p>
            </div>
        </header>
        <div class="panel app-messages__chat-log" data-chat-scroll tabindex="0" role="region" aria-label="История обращения">
            <div class="app-messages__load" data-chat-load hidden><span class="spinner" aria-hidden="true"></span><span>Загружаем историю…</span></div>
            <div class="app-messages__history" data-chat-history data-next-before="{{ $nextBefore }}" role="log">
                @foreach ($messages as $message)
                    @include('theme::pages.account.partials.chat-bubble', [
                        'entry' => ['kind' => 'message', 'id' => 'message-'.$message->id,
                            'text' => $message->body, 'at' => $message->created_at, 'sender' => $message->sender],
                    ])
                @endforeach
            </div>
        </div>
        @if ($canSend)
            <form class="panel app-messages__compose" data-chat-compose method="POST"
                  action="{{ route('account.notifications.threads.support.messages', $ticket) }}">
                @csrf
                <textarea name="body" required maxlength="2000" aria-label="Новое сообщение" placeholder="Введите сообщение..."></textarea>
                <div class="app-messages__compose-footer">
                    @if ($isAgent)
                        <details class="app-context-actions app-messages__actions" data-chat-actions>
                            @include('theme::pages.account.partials.chat-actions-trigger', ['ariaLabel' => 'Действия по обращению'])
                            <div class="app-context-actions__menu">
                                @if (! $thread->lead_user_id)
                                    <button type="submit" form="support-claim-{{ $ticket->id }}">Стать главным</button>
                                @endif
                                @if ($isLead)
                                    <button type="submit" form="support-close-{{ $ticket->id }}">Закрыть обращение</button>
                                    @if ($availableAgents->isNotEmpty())
                                        <label class="app-messages__support-transfer-label" for="support-transfer-select-{{ $ticket->id }}">Передать главенство</label>
                                        <select id="support-transfer-select-{{ $ticket->id }}" name="user_id" form="support-transfer-{{ $ticket->id }}" required>
                                            <option value="">Выберите сотрудника</option>
                                            @foreach ($availableAgents as $agent)
                                                <option value="{{ $agent->id }}">{{ $agent->username }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" form="support-transfer-{{ $ticket->id }}">Передать</button>
                                    @endif
                                @endif
                                <a href="{{ route('account.notifications') }}">Все сообщения</a>
                            </div>
                        </details>
                    @endif
                    <button type="submit" data-chat-send class="button primary">Отправить</button>
                </div>
            </form>
            @if ($isAgent && ! $thread->lead_user_id)
                <form id="support-claim-{{ $ticket->id }}" method="POST" action="{{ route('account.notifications.threads.support.claim', $ticket) }}">@csrf</form>
            @endif
            @if ($isLead)
                <form id="support-close-{{ $ticket->id }}" method="POST" action="{{ route('account.notifications.threads.support.close', $ticket) }}">@csrf</form>
                @if ($availableAgents->isNotEmpty())
                    <form id="support-transfer-{{ $ticket->id }}" method="POST" action="{{ route('account.notifications.threads.support.transfer', $ticket) }}">@csrf</form>
                @endif
            @endif
        @else
            <div class="panel app-messages__compose app-messages__system-footer"><p>Обращение закрыто. История доступна для чтения.</p></div>
        @endif
        <p role="alert" data-chat-error hidden></p>
    </section>
</div>
@endsection
