@extends('theme::layouts.account', ['title' => 'Сообщения — Приглашение на игру', 'compactSidebar' => true])

@section('account-content')
@php
    $game = $context->game;
    $event = $game?->event;
    $entries = $messages->getCollection()->map(fn ($message) => ['kind' => 'message', 'at' => $message->created_at, 'item' => $message])
        ->concat($invitations->map(fn ($invitation) => ['kind' => 'invitation', 'at' => $invitation->created_at, 'item' => $invitation]))
        ->sortBy('at');
@endphp
<div class="app-message-workspace">
    @include('theme::pages.account.partials.conversation-rail')
    <section class="app-messages app-messages--thread" aria-label="Приглашение на игру">
        <header class="panel app-messages__thread-header">
            <a class="app-messages__back" href="{{ route('account.notifications') }}" aria-label="Все сообщения"><svg aria-hidden="true"><use href="#arrow"/></svg></a>
            <span class="app-messages__avatar" aria-hidden="true"><svg><use href="#message"/></svg></span>
            <div class="app-messages__thread-contact">
                <h2>Приглашение на игру</h2>
                <p>{{ $context->team->name }} · {{ $game?->title ?: 'Игра' }}</p>
            </div>
        </header>
        <div class="panel app-messages__chat-log" role="region" aria-label="История переписки" tabindex="0">
            @if($messages->hasMorePages())
                <a class="button secondary" href="{{ $messages->nextPageUrl() }}">Предыдущие сообщения</a>
            @endif
            <div class="app-messages__history" role="log">
                @foreach($entries as $entry)
                    @php($item = $entry['item'])
                    @if($entry['kind'] === 'message')
                        @include('theme::pages.account.partials.chat-bubble', ['viewer' => $viewer, 'entry' => [
                            'kind' => 'message', 'id' => 'message-'.$item->id, 'sender' => $item->sender, 'text' => $item->body, 'at' => $item->created_at,
                        ]])
                    @else
                        <article class="app-messages__bubble app-messages__bubble--system">
                            <div class="app-messages__bubble-head"><strong>{{ $loop->first && $item->id === $invitations->first()?->id ? 'Приглашение на игру' : ($item->id === $invitations->first()?->id ? 'Приглашение на игру' : 'Повторное приглашение') }}</strong></div>
                            <p>{{ $game?->title ?: 'Игра' }} · {{ $context->team->name }}</p>
                            @if($game?->format)<p>{{ $game->formatLabel() }}</p>@endif
                            @if($event?->starts_at)<p>{{ $event->starts_at->timezone('Europe/Moscow')->format('d.m.Y H:i') }} (МСК)</p>@endif
                            @if($event?->venue)<p>{{ $event->venue->name }}</p>@endif
                            @if($event)<a href="{{ route('events.show', $event->routeIdentifier()) }}#planned-game-{{ $game->id }}">Перейти к мероприятию</a>@endif
                            <p><strong>{{ app(\App\Modules\Event\Application\Services\GameInvitationConversation::class)->statusLabel($item->status->value) }}</strong></p>
                            @if($item->response_comment)<p>{{ $item->response_comment }}</p>@endif
                            @if($canRespond && $item->status->value === 'pending')
                                <form method="POST" action="{{ route('account.notifications.threads.game-invitation.respond', [$context, $item->id]) }}" class="app-messages__compose-footer">
                                    @csrf
                                    <button type="submit" class="button secondary" name="decision" value="declined">Отказаться</button>
                                    <button type="submit" class="button primary" name="decision" value="accepted">Принять</button>
                                </form>
                            @endif
                            <time class="app-messages__timestamp" datetime="{{ $item->created_at->toIso8601String() }}">{{ $item->created_at->format('d.m.Y H:i') }}</time>
                        </article>
                    @endif
                @endforeach
            </div>
            @if($messages->previousPageUrl())<a href="{{ $messages->previousPageUrl() }}">Более новые сообщения</a>@endif
        </div>
        <form class="panel app-messages__compose" method="POST" action="{{ route('account.notifications.threads.game-invitation.messages', $context) }}">
            @csrf
            <label class="sr-only" for="game-invitation-message">Сообщение</label>
            <textarea id="game-invitation-message" name="body" rows="3" maxlength="2000" required placeholder="Введите сообщение…">{{ old('body') }}</textarea>
            @foreach($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
            <div class="app-messages__compose-footer"><button class="button primary" type="submit">Отправить</button></div>
        </form>
    </section>
</div>
@endsection
