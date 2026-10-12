@extends('theme::layouts.account', ['title' => 'Система MSKBA — сообщения'])

@section('account-heading')
    <header class="app-account-overview__intro">
        <h1>Сообщения</h1>
    </header>
@endsection

@section('account-content')
    <section class="app-messages app-messages--thread app-messages--system" aria-label="Переписка с системой">
        <header class="app-messages__thread-header">
            <a class="app-messages__back" href="{{ route('account.notifications') }}">
                <svg aria-hidden="true"><use href="#arrow"/></svg>
                Все сообщения
            </a>
            <div class="app-messages__thread-contact">
                <span class="app-messages__avatar" aria-hidden="true"><svg><use href="#message"/></svg></span>
                <div><h2>Система MSKBA</h2><p>Системные уведомления</p></div>
            </div>
        </header>

        @if ($notifications->isEmpty())
            <p class="app-messages__thread-empty">Сообщений пока нет.</p>
        @else
            <div class="app-messages__history" role="log" aria-label="История сообщений">
                @foreach ($notifications as $notification)
                    @php($messageActions = app(\App\Modules\Notification\Presentation\Presenters\UserNotificationPresenter::class)->present($notification))
                    <article class="app-messages__bubble app-messages__bubble--notification {{ $notification->isNew() ? 'is-unread' : '' }}"
                             data-conversation-message="{{ $notification->id }}">
                        <div class="app-messages__bubble-head">
                            <strong>{{ $notification->title }}</strong>
                            @if ($notification->isNew())<span class="app-messages__new-label">Новое</span>@endif
                        </div>
                        <p class="app-messages__bubble-body">{{ $notification->body }}</p>
                        @if ($messageActions['action_text'] && is_string($messageActions['href']) && str_starts_with($messageActions['href'], '/') && ! str_starts_with($messageActions['href'], '//'))
                            <a class="app-messages__action" href="{{ $messageActions['href'] }}">{{ $messageActions['action_text'] }}</a>
                        @endif
                        @foreach ($messageActions['actions'] as $action)
                            @if (str_starts_with($action['url'], '/') && ! str_starts_with($action['url'], '//'))
                                <form method="POST" action="{{ $action['url'] }}" class="app-messages__action-form">
                                    @csrf @method($action['method'])
                                    <input type="hidden" name="decision" value="{{ $action['key'] }}">
                                    <button type="submit" class="button {{ $action['variant'] }}">{{ $action['label'] }}</button>
                                </form>
                            @endif
                        @endforeach
                        <time datetime="{{ $notification->created_at?->toIso8601String() }}" class="app-messages__timestamp">
                            {{ $notification->created_at?->format('d.m.Y H:i') }}
                        </time>
                    </article>
                @endforeach
            </div>
        @endif

        @if ($notifications->hasPages())
            <nav class="app-messages__pagination" aria-label="Страницы переписки">
                @if ($notifications->previousPageUrl())
                    <a href="{{ $notifications->previousPageUrl() }}">← Более новые</a>
                @else
                    <span></span>
                @endif
                <span>Страница {{ $notifications->currentPage() }} из {{ $notifications->lastPage() }}</span>
                @if ($notifications->nextPageUrl())
                    <a href="{{ $notifications->nextPageUrl() }}">Более ранние →</a>
                @else
                    <span></span>
                @endif
            </nav>
        @endif

        <div class="panel app-messages__support">
            <strong>Нужна помощь?</strong>
            <p>Если возникли вопросы по системному сообщению, можно обратиться в поддержку MSKBA.</p>
            <a href="{{ url('/contacts') }}" target="_blank" rel="noopener noreferrer">Связаться с поддержкой <svg aria-hidden="true"><use href="#arrow-up-right"/></svg></a>
        </div>
        <p class="app-messages__read-error" role="alert" data-message-read-error hidden></p>
        <div data-system-thread-read-url="{{ route('account.notifications.threads.system.read') }}"
             data-unread-count="{{ $systemConversation['unread_count'] ?? 0 }}"
             data-page="{{ $notifications->currentPage() }}"></div>
    </section>
@endsection
