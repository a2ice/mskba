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
                <p>{{ ($context->team?->name ?? 'Команда') }} · {{ $game?->title ?: 'Игра' }}</p>
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
                            <div class="app-messages__bubble-head"><strong>{{ $item->id === $invitations->first()?->id ? 'Приглашение на игру' : 'Повторное приглашение' }}</strong></div>
                            <p>{{ $game?->title ?: 'Игра' }} · {{ ($context->team?->name ?? 'Команда') }}</p>
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
                            @if($canNotify && $item->id === $latestInvitation?->id)
                                @if($players->contains(fn ($player) => !in_array((int) $player->id, $notified, true)))
                                    <button type="button" class="button secondary" data-game-teammates-open>Оповестить тиммейтов</button>
                                @elseif($players->isNotEmpty())
                                    <p>Все действующие игроки оповещены.</p>
                                @else
                                    <p>В команде пока нет действующих игроков.</p>
                                @endif
                                @if(count($notified))<p>Оповещено игроков: {{ count($notified) }}</p>@endif
                            @endif
                            <time class="app-messages__timestamp" datetime="{{ $item->created_at->toIso8601String() }}">{{ $item->created_at->format('d.m.Y H:i') }}</time>
                        </article>
                    @endif
                @endforeach
            </div>
            @if($messages->previousPageUrl())<a href="{{ $messages->previousPageUrl() }}">Более новые сообщения</a>@endif
        </div>
        @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if($canNotify && $players->isNotEmpty())
            <dialog class="mskba-modal event-teammates-dialog" data-game-teammates-dialog aria-labelledby="game-teammates-title">
                <header class="mskba-modal__header"><h2 id="game-teammates-title">Оповестить тиммейтов</h2></header>
                <form method="POST" action="{{ route('account.notifications.threads.game-invitation.notify-teammates', [$context, $latestInvitation->id]) }}" data-game-teammates-form>
                    @csrf
                    <div class="mskba-modal__body mskba-scroll">
                        <p>Сообщить игрокам команды о предстоящей игре. Это не подтверждает их участие.</p>
                        <label class="check-label"><input type="checkbox" data-game-teammates-all checked> Все участники команды</label>
                        @foreach($players as $player)
                            @php($already = in_array((int) $player->id, $notified, true))
                            <label class="check-label">
                                <input type="checkbox" name="recipients[]" value="{{ $player->id }}" @checked(!$already) @disabled($already)>
                                {{ $player->username ?: 'Игрок #'.$player->id }} @if($already)<small>Оповещён</small>@endif
                            </label>
                        @endforeach
                        <label for="game-teammates-comment">Сообщение от себя (необязательно)</label>
                        <textarea id="game-teammates-comment" name="comment" rows="3" maxlength="1000" placeholder="Ребята, кто сможет сыграть?"></textarea>
                        @error('recipients')<p role="alert">{{ $message }}</p>@enderror
                        @error('recipients.*')<p role="alert">{{ $message }}</p>@enderror
                    </div>
                    <footer class="mskba-modal__footer"><button type="button" class="button secondary" data-game-teammates-close>Отмена</button><button type="submit" class="button primary" data-game-teammates-submit>Оповестить</button></footer>
                </form>
            </dialog>
        @endif
        <form class="panel app-messages__compose" method="POST" action="{{ route('account.notifications.threads.game-invitation.messages', $context) }}">
            @csrf
            <label class="sr-only" for="game-invitation-message">Сообщение</label>
            <textarea id="game-invitation-message" name="body" rows="3" maxlength="2000" required placeholder="Введите сообщение…">{{ old('body') }}</textarea>
            @foreach($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
            <div class="app-messages__compose-footer"><button class="button primary" type="submit">Отправить</button></div>
        </form>
    </section>
</div>
<style>
.event-teammates-dialog>form{display:flex;flex-direction:column;min-height:0;overflow:hidden}
.event-teammates-dialog .mskba-modal__body{display:grid;gap:12px;padding:20px;overflow:auto}
.event-teammates-dialog .mskba-modal__header,.event-teammates-dialog .mskba-modal__footer{padding:16px 20px}
.event-teammates-dialog .mskba-modal__footer{display:flex;justify-content:flex-end;gap:8px}
.event-teammates-dialog .check-label{display:flex;align-items:center;gap:8px}
.event-teammates-dialog .check-label input{width:20px;height:20px;flex:none;margin:0}
</style>
<script>
(() => {
    const dialog=document.querySelector('[data-game-teammates-dialog]');
    if(!dialog)return;
    const all=dialog.querySelector('[data-game-teammates-all]');
    const checks=[...dialog.querySelectorAll('input[name="recipients[]"]:not(:disabled)')];
    const submit=dialog.querySelector('[data-game-teammates-submit]');
    const sync=()=>{const count=checks.filter(check=>check.checked).length;all.checked=count===checks.length&&count>0;all.indeterminate=count>0&&count<checks.length;all.disabled=!checks.length;submit.disabled=!count;};
    all.addEventListener('change',()=>{checks.forEach(check=>check.checked=all.checked);sync();});
    checks.forEach(check=>check.addEventListener('change',sync));
    document.querySelectorAll('[data-game-teammates-open]').forEach(button=>button.addEventListener('click',()=>{sync();dialog.showModal();}));
    dialog.querySelector('[data-game-teammates-close]').addEventListener('click',()=>dialog.close());
    dialog.querySelector('form').addEventListener('submit',()=>{submit.disabled=true;});
    sync();
    @if($errors->has('recipients') || $errors->has('recipients.*')) dialog.showModal(); @endif
})();
</script>
@endsection
