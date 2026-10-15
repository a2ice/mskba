<div class="mskba-support-chat" data-filament-operator-chat
    data-operator-thread-id="{{ $conversation->id }}"
    data-operator-state-url="{{ route('account.notifications.threads.operator.state', $conversation) }}"
    wire:poll.visible.12s="refreshConversation">
    @once
        <style>
            .mskba-support-chat{display:flex;flex-direction:column;gap:1rem;color:inherit}
            .mskba-support-chat__history{height:min(56vh,540px);min-height:270px;overflow:auto;overscroll-behavior:contain;padding:1rem;background:rgba(100,116,139,.06);border:1px solid rgba(100,116,139,.18);border-radius:1rem;display:flex;flex-direction:column;gap:.75rem}
            .dark .mskba-support-chat__history{background:rgba(15,23,42,.3)}
            .mskba-support-chat__bubble{max-width:min(83%,540px);align-self:flex-start;border:1px solid rgba(100,116,139,.18);padding:.65rem .85rem;border-radius:1rem;background:rgba(100,116,139,.1);overflow-wrap:anywhere}
            .mskba-support-chat__bubble--mine{align-self:flex-end;background:rgba(234,117,24,.13);border-color:rgba(234,117,24,.35)}
            .mskba-support-chat__name{display:block;font-weight:600;font-size:.76rem;margin-bottom:.2rem}
            .mskba-support-chat__date{font-size:.7rem;opacity:.62;margin-top:.3rem;display:block;text-align:right}
            .mskba-support-chat__text{white-space:pre-wrap;word-break:break-word;font-size:.88rem}
            .mskba-support-chat__compose{display:grid;gap:.7rem}
            .mskba-support-chat__textarea{border:1px solid rgba(100,116,139,.40);border-radius:.8rem;width:100%;min-height:84px;padding:.8rem;background:transparent;color:inherit;resize:vertical}
            .mskba-support-chat__textarea:focus-visible{outline:2px solid #f97316;outline-offset:2px}
            .mskba-support-chat__send{border-radius:.7rem;background:#ea700c;color:#fff;padding:.65rem 1.2rem;font-size:.87rem;font-weight:600}
            .mskba-support-chat__send:disabled{opacity:.55;cursor:progress}
            .mskba-support-chat__footer{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.65rem}
            .mskba-support-chat__secondary{font-size:.78rem;opacity:.7}
            .mskba-support-chat__older{align-self:center;font-size:.8rem;text-decoration:underline}
            .mskba-support-chat__banner{background:rgba(100,116,139,.1);border-radius:.7rem;padding:1rem;font-size:.88rem}
            .mskba-operator-presence{display:flex;align-items:center;flex-wrap:wrap;gap:.3rem .65rem;padding:.7rem .85rem;background:rgba(100,116,139,.06);border:1px solid rgba(100,116,139,.18);border-radius:.8rem;font-size:.83rem}
            .mskba-operator-presence__status{display:inline-flex;align-items:center;gap:.4rem;white-space:nowrap;font-weight:600}
            .mskba-operator-presence__dot{width:.55rem;height:.55rem;flex-shrink:0;border-radius:50%;background:#9ca3af}
            .mskba-operator-presence__status--online .mskba-operator-presence__dot{background:#16a34a}
            .mskba-operator-presence__path{overflow-wrap:anywhere;word-break:break-word}
            .mskba-operator-presence__updated{font-size:.72rem;opacity:.65}
            @media(max-width:640px){.mskba-support-chat__history{min-height:290px;max-height:55dvh}.mskba-support-chat__bubble{max-width:95%}}
        </style>
    @endonce
    <p class="mskba-support-chat__secondary">Сервисная переписка с {{ $conversation->recipient->username }} · обновление через Reverb</p>
    @if ($canSeePresence)
        <div class="mskba-operator-presence" data-operator-presence
            aria-label="Присутствие пользователя" aria-live="polite" aria-atomic="true">
            <span class="mskba-operator-presence__status{{ ($presence['online'] ?? false) ? ' mskba-operator-presence__status--online' : '' }}">
                <span class="mskba-operator-presence__dot" aria-hidden="true"></span>
                {{ ($presence['online'] ?? false) ? 'Онлайн' : 'Офлайн' }}
            </span>
            @if ($presence)
                <span class="mskba-operator-presence__path">
                    {{ $presence['online'] ? 'Сейчас на странице:' : 'Последняя страница:' }}
                    <strong>{{ $presence['path'] }}</strong>
                </span>
                <time class="mskba-operator-presence__updated"
                    datetime="{{ \Carbon\Carbon::createFromTimestamp($presence['at'])->toIso8601String() }}"
                    title="Время последней активности">
                    {{ \Carbon\Carbon::createFromTimestamp($presence['at'])->format('d.m.Y H:i:s') }}
                </time>
            @else
                <span class="mskba-support-chat__secondary">Данные об активности пока отсутствуют.</span>
            @endif
        </div>
    @endif
    <div class="mskba-support-chat__history" data-filament-operator-history
        role="log" aria-label="История переписки" aria-live="polite">
        @if ($hasOlder)
            <button type="button" class="mskba-support-chat__older" wire:click="loadOlder"
                wire:loading.attr="disabled" wire:target="loadOlder">Показать предыдущие</button>
        @elseif ($moreOnPortal)
            <a class="mskba-support-chat__older"
               href="{{ route('account.notifications.threads.operator', $conversation) }}"
               target="_blank" rel="noopener noreferrer">Более ранние сообщения</a>
        @endif
        @forelse ($messages as $message)
            <article wire:key="operator-message-{{ $message->id }}"
                class="mskba-support-chat__bubble{{ (int) $message->sender_user_id === (int) $viewer->id ? ' mskba-support-chat__bubble--mine' : '' }}">
                <strong class="mskba-support-chat__name">{{ (int) $message->sender_user_id === (int) $viewer->id ? 'Вы' : ($message->sender?->username ?? 'Участник') }}</strong>
                <p class="mskba-support-chat__text">{{ $message->body }}</p>
                <time class="mskba-support-chat__date" datetime="{{ $message->created_at?->toIso8601String() }}">{{ $message->created_at?->format('d.m.Y H:i') }}</time>
            </article>
        @empty
            <p class="mskba-support-chat__secondary">Сообщений пока нет.</p>
        @endforelse
    </div>
    @if ($canSend)
        <form class="mskba-support-chat__compose" wire:submit="send">
            <textarea aria-label="Сообщение пользователю" class="mskba-support-chat__textarea"
                wire:model="draft" maxlength="2000" required
                placeholder="Введите сообщение…"></textarea>
            @error('draft')<p role="alert" style="color:#dc2626">{{ $message }}</p>@enderror
            <div class="mskba-support-chat__footer">
                <span class="mskba-support-chat__secondary">До 2000 символов · отправка видна пользователю</span>
                <button type="submit" class="mskba-support-chat__send" wire:loading.attr="disabled" wire:target="send">
                    <span wire:loading.remove wire:target="send">Отправить</span>
                    <span wire:loading wire:target="send">Отправка…</span>
                </button>
            </div>
        </form>
    @else
        <div class="mskba-support-chat__banner">Диалог недоступен для отправки.</div>
    @endif
</div>
