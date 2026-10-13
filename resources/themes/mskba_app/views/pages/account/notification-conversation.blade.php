@extends('theme::layouts.account', ['title' => 'Сообщения — Система MSKBA', 'compactSidebar' => true])

@section('account-content')
<div class="app-message-workspace">
    @include('theme::pages.account.partials.conversation-rail')
    <section class="app-messages app-messages--thread app-messages--system" aria-label="Уведомления системы"
             data-chat-root data-chat-system-notifications data-chat-history-url="{{ route('account.notifications.threads.system.history') }}"
             data-chat-read-url="{{ route('account.notifications.threads.system.read') }}"
             data-chat-unread-initial="{{ $systemConversation['unread_count'] ?? 0 }}">
        <header class="panel app-messages__thread-header">
            <a class="app-messages__back" href="{{ route('account.notifications') }}" aria-label="Все сообщения">
                <svg aria-hidden="true"><use href="#arrow"/></svg>
            </a>
            <span class="app-messages__avatar" aria-hidden="true"><svg><use href="#message"/></svg></span>
            <div class="app-messages__thread-contact"><h2>Система MSKBA</h2><p>Автоматические уведомления</p></div>
        </header>
        <div class="panel app-messages__chat-log" data-chat-scroll tabindex="0" role="region" aria-label="История системных уведомлений">
            <div class="app-messages__load" data-chat-load hidden>
                <span class="spinner" aria-hidden="true"></span><span>Загружаем историю…</span>
            </div>
            <div class="app-messages__history" data-chat-history data-next-before="{{ $nextBefore }}" role="log" aria-label="История уведомлений">
                @forelse ($notifications as $notification)
                    @include('theme::pages.account.partials.system-notification-bubble')
                @empty
                    <p class="app-messages__thread-empty">Уведомлений пока нет.</p>
                @endforelse
            </div>
        </div>
        <div class="panel app-messages__compose app-messages__system-footer">
            <p>Это автоматические уведомления. Для вопросов и ответов используйте отдельную переписку с поддержкой.</p>
            <a class="button primary" href="{{ route('account.notifications.support.start') }}">Написать в техподдержку</a>
        </div>
        <p role="alert" class="app-messages__read-error" data-chat-error hidden></p>
    </section>
</div>
@endsection
