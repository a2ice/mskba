@extends('theme::layouts.account', ['title' => 'Заявка в команду — сообщения'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Сообщения',
    ])
@endsection

@section('account-content')
    <section class="app-messages app-messages--thread" aria-label="Переписка по заявке">
        <header class="app-messages__thread-header">
            <a class="app-messages__back" href="{{ route('account.notifications') }}">
                <svg aria-hidden="true"><use href="#arrow"/></svg> Все сообщения
            </a>
            <div class="app-messages__thread-contact">
                <span class="app-messages__avatar" aria-hidden="true"><svg><use href="#people"/></svg></span>
                <div>
                    <h2>Заявка в команду «{{ $joinRequest->team->name }}»</h2>
                    <p>{{ $joinRequest->status->label() }}</p>
                </div>
            </div>
            @if ($participants->isNotEmpty())
                <div class="app-messages__participants">
                    <span>Участники:</span>
                    @foreach ($participants as $participant)
                        @php($participantName = $participant->username ?: (trim(($participant->profile?->first_name ?? '').' '.($participant->profile?->last_name ?? '')) ?: 'Участник #'.$participant->id))
                        <button type="button" class="app-messages__participant"
                                data-direct-message-open data-recipient-id="{{ $participant->id }}"
                                data-recipient-name="{{ $participantName }}">{{ $participantName }}</button>
                    @endforeach
                </div>
            @endif
        </header>

        <div class="app-messages__history" role="log" aria-label="История заявки">
            @foreach ($timeline as $entry)
                @if ($entry['kind'] === 'system')
                    <article class="app-messages__bubble app-messages__bubble--system" data-conversation-event="{{ $entry['key'] }}">
                        <div class="app-messages__bubble-head"><strong>{{ $entry['heading'] }}</strong></div>
                        <p class="app-messages__bubble-body">{{ $entry['body'] }}</p>
                        <time class="app-messages__timestamp" datetime="{{ $entry['created_at']?->toIso8601String() }}">{{ $entry['created_at']?->format('d.m.Y H:i') }}</time>
                    </article>
                @else
                    @php($speaker = $entry['sender'])
                    @php($speakerName = $speaker?->username ?: (trim(($speaker?->profile?->first_name ?? '').' '.($speaker?->profile?->last_name ?? '')) ?: 'Участник'))
                    <article class="app-messages__bubble {{ $entry['mine'] ? 'app-messages__bubble--outgoing' : 'app-messages__bubble--incoming' }}"
                             data-conversation-event="{{ $entry['key'] }}">
                        <div class="app-messages__bubble-head">
                            @if ($entry['mine'])
                                <strong>Вы</strong>
                            @elseif ($speaker)
                                <button type="button" class="app-messages__participant" data-direct-message-open
                                        data-recipient-id="{{ $speaker->id }}" data-recipient-name="{{ $speakerName }}">{{ $speakerName }}</button>
                            @else
                                <strong>Участник</strong>
                            @endif
                        </div>
                        <p class="app-messages__bubble-body">{{ $entry['body'] }}</p>
                        <time class="app-messages__timestamp" datetime="{{ $entry['created_at']?->toIso8601String() }}">{{ $entry['created_at']?->format('d.m.Y H:i') }}</time>
                    </article>
                @endif
            @endforeach
        </div>

        @if ($canWrite)
            <form class="app-messages__compose" action="{{ route('account.notifications.threads.team.messages', $joinRequest->id) }}" method="POST">
                @csrf
                <label for="team-message-body">Написать по заявке</label>
                <textarea id="team-message-body" name="body" rows="3" maxlength="2000" required placeholder="Введите сообщение…"></textarea>
                @error('body')<p role="alert">{{ $message }}</p>@enderror
                <button type="submit" class="button primary">Отправить</button>
            </form>
        @else
            <p class="app-messages__closed">Переписка закрыта: заявка принята или заблокирована.</p>
        @endif
        <div data-conversation-read-url="{{ route('account.notifications.threads.team.read', $joinRequest->id) }}"
             data-unread-count="{{ $timeline->filter(fn ($entry) => $entry['notification']?->isNew())->count() }}"></div>
        <p class="app-messages__read-error" role="alert" data-message-read-error hidden></p>
    </section>
    @include('theme::pages.account.partials.direct-message-dialog')
@endsection
