@extends('theme::layouts.account', ['title' => 'Личная переписка'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', ['heading' => 'Сообщения'])
@endsection

@section('account-content')
    @php($contactName = $recipient->username ?: (trim(($recipient->profile?->first_name ?? '').' '.($recipient->profile?->last_name ?? '')) ?: 'Участник'))
    <section class="app-messages app-messages--thread" aria-label="Личная переписка">
        <header class="app-messages__thread-header">
            <a class="app-messages__back" href="{{ route('account.notifications') }}"><svg aria-hidden="true"><use href="#arrow"/></svg>Все сообщения</a>
            <div class="app-messages__thread-contact">
                <span class="app-messages__avatar" aria-hidden="true"><svg><use href="#message"/></svg></span>
                <div><h2>{{ $contactName }}</h2><p>Личная переписка</p></div>
            </div>
        </header>
        <div class="app-messages__history" role="log" aria-label="История переписки">
            @foreach ($messages as $message)
                @php($mine = $viewer->isSameIdentity((int) $message->sender_user_id))
                <article class="app-messages__bubble {{ $mine ? 'app-messages__bubble--outgoing' : 'app-messages__bubble--incoming' }}">
                    <div class="app-messages__bubble-head"><strong>{{ $mine ? 'Вы' : $contactName }}</strong></div>
                    <p class="app-messages__bubble-body">{{ $message->body }}</p>
                    <time class="app-messages__timestamp" datetime="{{ $message->created_at?->toIso8601String() }}">{{ $message->created_at?->format('d.m.Y H:i') }}</time>
                </article>
            @endforeach
        </div>
        @if ($messages->hasPages())
            <nav class="app-messages__pagination" aria-label="Страницы переписки">
                <a href="{{ $messages->previousPageUrl() ?: '#' }}" @if(!$messages->previousPageUrl()) aria-disabled="true" @endif>← Более новые</a>
                <span>Страница {{ $messages->currentPage() }} из {{ $messages->lastPage() }}</span>
                <a href="{{ $messages->nextPageUrl() ?: '#' }}" @if(!$messages->nextPageUrl()) aria-disabled="true" @endif>Более ранние →</a>
            </nav>
        @endif
        <form class="app-messages__compose" data-direct-thread-compose
              data-recipient-id="{{ $recipient->id }}"
              data-send-url="{{ route('account.notifications.direct.send', $recipient->id) }}">
            <label for="direct-thread-message">Написать сообщение</label>
            <textarea id="direct-thread-message" name="body" rows="3" maxlength="2000" required></textarea>
            <button class="button primary" type="submit">Отправить</button>
            <p data-direct-thread-error role="alert" hidden></p>
        </form>
        <div data-conversation-read-url="{{ route('account.notifications.threads.direct.read', $conversation->id) }}"
             data-unread-count="{{ $unread }}"></div>
    </section>
@endsection
