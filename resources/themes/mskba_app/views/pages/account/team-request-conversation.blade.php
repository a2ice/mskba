@extends('theme::layouts.account', ['title' => 'Заявка в команду — сообщения', 'compactSidebar' => true])

@section('account-content')
<div class="app-message-workspace" data-team-chat-root
     data-team-state-url="{{ route('account.notifications.threads.team.state', $joinRequest->id) }}"
     data-team-read-url="{{ route('account.notifications.threads.team.read', $joinRequest->id) }}">
    @include('theme::pages.account.partials.conversation-rail')
    <section class="app-messages app-messages--thread" aria-label="Переписка по заявке">
        <header class="panel app-messages__thread-header">
            <a class="app-messages__back" href="{{ route('account.notifications') }}" aria-label="Все сообщения">
                <svg aria-hidden="true"><use href="#arrow"/></svg>
            </a>
            <span class="app-messages__avatar" aria-hidden="true"><svg><use href="#people"/></svg></span>
            <div class="app-messages__thread-contact">
                <h2>Заявка в команду «{{ $joinRequest->team->name }}»</h2>
                <p data-team-status-label>{{ $joinRequest->status->label() }}</p>
            </div>
        </header>
        @if($participants->isNotEmpty())
            <details class="panel app-messages__thread-participants">
                <summary>Участники ({{ $participants->count() }})</summary>
                <div class="app-messages__participants">
                    @foreach($participants as $participant)
                        @php($participantName = $participant->username ?: (trim(($participant->profile?->first_name ?? '').' '.($participant->profile?->last_name ?? '')) ?: 'Участник #'.$participant->id))
                        <button type="button" class="app-messages__participant"
                                data-direct-message-open data-recipient-id="{{ $participant->id }}"
                                data-recipient-name="{{ $participantName }}">{{ $participantName }}</button>
                    @endforeach
                </div>
            </details>
        @endif
        <div class="panel app-messages__chat-log" data-team-chat-scroll tabindex="0" role="region" aria-label="История заявки">
            <div class="app-messages__history" data-team-chat-history role="log" aria-live="polite">
                @include('theme::pages.account.partials.team-request-timeline')
            </div>
        </div>
        <form class="panel app-messages__compose" data-team-chat-compose
              action="{{ route('account.notifications.threads.team.messages', $joinRequest->id) }}" method="POST">
            @csrf
            <label class="sr-only" for="team-message-body">Написать по заявке</label>
            <textarea id="team-message-body" name="body" rows="3" maxlength="2000" required
                      @disabled(!$canWrite) @if(!$canWrite) hidden @endif
                      placeholder="Введите сообщение…"></textarea>
            <p class="app-messages__team-closed" data-team-chat-closed @if($canWrite) hidden @endif>
                Переписка закрыта. История доступна для чтения.
            </p>
            <div class="app-messages__compose-footer">
                <div data-team-action-panel>
                    @include('theme::pages.account.partials.team-request-actions')
                </div>
                <button type="submit" class="button primary" data-team-chat-send @if(!$canWrite) hidden @endif>
                    Отправить
                </button>
            </div>
            <p class="app-messages__read-error" data-team-chat-error role="alert" hidden></p>
        </form>
        <div data-conversation-read-url="{{ route('account.notifications.threads.team.read', $joinRequest->id) }}"
             data-unread-count="{{ $timeline->filter(fn ($entry) => $entry['notification']?->isNew())->count() }}"></div>
    </section>
</div>
@include('theme::pages.account.partials.direct-message-dialog')
@endsection
