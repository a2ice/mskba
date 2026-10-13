<aside class="panel app-messages__rail" aria-label="Другие сообщения"
       data-conversation-rail data-rail-url="{{ route('account.notifications.rail') }}"
       data-rail-active="{{ $activeConversationId }}">
    <header class="app-messages__rail-header">
        <strong>Сообщения</strong>
        <a href="{{ route('account.notifications') }}" title="Все диалоги">Все</a>
    </header>
    <nav class="app-messages__rail-list" data-rail-list aria-label="Список диалогов">
        @include('theme::pages.account.partials.conversation-rail-items')
    </nav>
</aside>
