@php
    $game = $context->game;
    $event = $game?->event;
    $entries = $messages->getCollection()->map(fn ($message) => ['kind' => 'message', 'at' => $message->created_at, 'item' => $message])
        ->concat($invitations->map(fn ($invitation) => ['kind' => 'invitation', 'at' => $invitation->created_at, 'item' => $invitation]))
        ->sortBy('at');
@endphp
        <div class="panel app-messages__chat-log" role="region" aria-label="История переписки" tabindex="0" data-game-chat-scroll>
            @if($messages->hasMorePages())
                <a class="button secondary" href="{{ $messages->nextPageUrl() }}">Предыдущие сообщения</a>
            @endif
            <div class="app-messages__history" role="log">
                @if($game?->cancelled_at)
                    <article class="app-messages__bubble app-messages__bubble--system">
                        <strong>Игра отменена</strong>
                        @if($game->cancellation_reason)<p>Причина: {{ $game->cancellation_reason }}</p>@endif
                        <time datetime="{{ $game->cancelled_at->toIso8601String() }}">{{ $game->cancelled_at->format('d.m.Y H:i') }}</time>
                    </article>
                @endif
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
                            @if($event)<a href="{{ route('events.show', $event->routeIdentifier()) }}{{ $game->trashed() ? '' : '#planned-game-'.$game->id }}">Перейти к мероприятию</a>@endif
                            <p><strong>{{ app(\App\Modules\Event\Application\Services\GameInvitationConversation::class)->statusLabel($item->status->value) }}</strong></p>
                            @if($item->response_comment)<p>{{ $item->response_comment }}</p>@endif
                            @if($canRespond && $item->status->value === 'pending')
                                <form data-game-chat-respond method="POST" action="{{ route('account.notifications.threads.game-invitation.respond', [$context, $item->id]) }}" class="app-messages__compose-footer">
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
                        <p data-game-teammates-error role="alert" hidden></p>
                        @error('recipients')<p role="alert">{{ $message }}</p>@enderror
                        @error('recipients.*')<p role="alert">{{ $message }}</p>@enderror
                    </div>
                    <footer class="mskba-modal__footer"><button type="button" class="button secondary" data-game-teammates-close>Отмена</button><button type="submit" class="button primary" data-game-teammates-submit>Оповестить</button></footer>
                </form>
            </dialog>
        @endif
