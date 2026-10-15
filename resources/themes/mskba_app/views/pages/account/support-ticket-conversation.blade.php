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
                        'entry' => $message,
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
                                    <button type="button" data-support-close-trigger>Закрыть обращение</button>
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
                @if ($ticket->status === 'open')
                    <dialog class="app-support-close-dialog" data-support-close-dialog aria-labelledby="support-close-title-{{ $ticket->id }}">
                        <form method="POST" action="{{ route('account.notifications.threads.support.close', $ticket) }}">
                            @csrf
                            <h3 id="support-close-title-{{ $ticket->id }}">Закрыть обращение №{{ $ticket->id }}</h3>
                            <label for="support-close-reason-{{ $ticket->id }}">Причина закрытия</label>
                            <select id="support-close-reason-{{ $ticket->id }}" name="reason" data-support-close-reason required>
                                @foreach (\App\Modules\Notification\Domain\Enums\SupportClosureReasonEnum::cases() as $reason)
                                    <option value="{{ $reason->value }}" @selected(old('reason', 'resolved') === $reason->value)>{{ $reason->label() }}</option>
                                @endforeach
                            </select>
                            <div data-support-close-description-wrap hidden>
                                <label for="support-close-description-{{ $ticket->id }}">Описание причины</label>
                                <textarea id="support-close-description-{{ $ticket->id }}" name="description"
                                          data-support-close-description maxlength="1000"
                                          placeholder="Опишите причину закрытия...">{{ old('description') }}</textarea>
                            </div>
                            @error('reason')<p role="alert">{{ $message }}</p>@enderror
                            @error('description')<p role="alert">{{ $message }}</p>@enderror
                            <div class="app-support-close-dialog__actions">
                                <button type="button" class="button secondary" data-support-close-cancel>Отмена</button>
                                <button type="submit" class="button primary">Закрыть обращение</button>
                            </div>
                        </form>
                    </dialog>
                @endif
                @if ($availableAgents->isNotEmpty())
                    <form id="support-transfer-{{ $ticket->id }}" method="POST" action="{{ route('account.notifications.threads.support.transfer', $ticket) }}">@csrf</form>
                @endif
            @endif
        @else
            <div class="panel app-messages__compose app-messages__system-footer">
                <p>Обращение закрыто. История доступна для чтения.</p>
                @if ($ticket->closed_by_user_id)
                    <p>Закрыл: {{ $ticket->closer?->username ?? 'Сотрудник поддержки' }}.
                        Причина: {{ \App\Modules\Notification\Domain\Enums\SupportClosureReasonEnum::tryFrom($ticket->close_reason ?? '')?->label() ?? 'Не указана' }}.
                        @if ($ticket->close_description) Описание: {{ $ticket->close_description }}. @endif
                    </p>
                @endif
                @if ($canReopen)
                    <form method="POST" action="{{ route('account.notifications.threads.support.reopen', $ticket) }}">
                        @csrf
                        <button class="button primary" type="submit">Возобновить обращение</button>
                    </form>
                @endif
            </div>
        @endif
        <p role="alert" data-chat-error hidden></p>
    </section>
</div>
@endsection
