<div class="mskba-support-chat"
     data-filament-support-chat data-support-thread-id="{{ $thread->id }}"
     data-support-state-url="{{ route('account.notifications.threads.support.state', $ticket) }}"
     wire:poll.visible.30s="refreshConversation">
    @once
        @vite('resources/js/backoffice-support-chat.js')
        <style>
            .mskba-support-chat{display:flex;flex-direction:column;gap:1rem;color:inherit}
            .mskba-support-chat__toolbar{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
            .mskba-support-chat__status{display:inline-flex;align-items:center;gap:.4rem;font-size:.8rem}
            .mskba-support-chat__status-dot{width:.5rem;height:.5rem;border-radius:50%;background:#16a34a}
            .mskba-support-chat__status--closed .mskba-support-chat__status-dot{background:#9ca3af}
            .mskba-support-chat__history{height:min(56vh,540px);min-height:270px;overflow:auto;overscroll-behavior:contain;padding:1rem;background:rgba(100,116,139,.06);border:1px solid rgba(100,116,139,.18);border-radius:1rem;display:flex;flex-direction:column;gap:.75rem}
            .dark .mskba-support-chat__history{background:rgba(15,23,42,.30)}
            .mskba-support-chat__bubble{max-width:min(83%,540px);align-self:flex-start;border:1px solid rgba(100,116,139,.18);padding:.65rem .85rem;border-radius:1rem;background:rgba(100,116,139,.10);overflow-wrap:anywhere}
            .mskba-support-chat__bubble--mine{align-self:flex-end;background:rgba(234,117,24,.13);border-color:rgba(234,117,24,.35)}
            .mskba-support-chat__bubble--system{max-width:100%;align-self:center;text-align:center;font-size:.82rem;background:transparent;color:inherit;opacity:.75;border:0}
            .mskba-support-chat__name{display:block;font-weight:600;font-size:.76rem;margin-bottom:.2rem}
            .mskba-support-chat__date{font-size:.7rem;opacity:.62;margin-top:.3rem;display:block;text-align:right}
            .mskba-support-chat__source{font-size:.75rem;margin-bottom:.4rem}
            .mskba-support-chat__source a{text-decoration:underline}
            .mskba-support-chat__text{white-space:pre-wrap;word-break:break-word;font-size:.88rem}
            .mskba-support-chat__compose{display:grid;gap:.7rem}
            .mskba-support-chat__textarea{border:1px solid rgba(100,116,139,.40);border-radius:.8rem;width:100%;min-height:84px;padding:.8rem;background:transparent;color:inherit;resize:vertical}
            .mskba-support-chat__textarea:focus-visible{outline:2px solid #f97316;outline-offset:2px}
            .mskba-support-chat__send{border-radius:.7rem;background:#ea700c;color:#fff;padding:.65rem 1.2rem;font-size:.87rem;font-weight:600}
            .mskba-support-chat__send:hover{background:#c95d09}
            .mskba-support-chat__send:disabled{opacity:.55;cursor:progress}
            .mskba-support-chat__footer{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.65rem}
            .mskba-support-chat__secondary{font-size:.78rem;opacity:.7}
            .mskba-support-chat__older{align-self:center;font-size:.8rem;text-decoration:underline;text-underline-offset:3px}
            .mskba-support-chat__banner{background:rgba(100,116,139,.10);border-radius:.7rem;padding:1rem;font-size:.88rem}
            @media(max-width:640px){.mskba-support-chat__history{min-height:290px;max-height:55dvh}.mskba-support-chat__bubble{max-width:95%}}
        </style>
    @endonce

    <header class="mskba-support-chat__toolbar">
        <div class="mskba-support-chat__status{{ $ticket->status === 'closed' ? ' mskba-support-chat__status--closed' : '' }}">
            <span class="mskba-support-chat__status-dot" aria-hidden="true"></span>
            {{ $ticket->status === 'open' ? 'Обращение открыто' : 'Обращение закрыто' }}
            <span class="mskba-support-chat__secondary">· №{{ $ticket->id }}</span>
        </div>
        <span class="mskba-support-chat__secondary">Обновление в реальном времени</span>
    </header>

    <div class="mskba-support-chat__history" data-filament-support-history aria-label="История переписки" role="log" aria-live="polite">
        @if ($hasOlder)
            <button type="button" class="mskba-support-chat__older" wire:click="loadOlder" wire:loading.attr="disabled" wire:target="loadOlder">
                Показать предыдущие сообщения
            </button>
        @endif

        @if ($moreOnPortal)
            <a class="mskba-support-chat__older" href="{{ route('account.notifications.threads.support', $ticket) }}" target="_blank" rel="noopener noreferrer">Более ранние сообщения — открыть полную историю</a>
        @endif

        @forelse ($messages as $entry)
            @php
                $isSystem = $entry['kind'] === 'system';
                $isMine = !$isSystem && (int) ($entry['sender']?->id ?? 0) === $viewer->id;
            @endphp
            <article wire:key="support-entry-{{ $entry['id'] }}"
                class="mskba-support-chat__bubble{{ $isSystem ? ' mskba-support-chat__bubble--system' : ($isMine ? ' mskba-support-chat__bubble--mine' : '') }}">
                <strong class="mskba-support-chat__name">{{ $isSystem ? 'Система MSKBA' : ($isMine ? 'Вы' : ($entry['sender']?->username ?? 'Участник')) }}</strong>
                @if (! empty($entry['source_path']))
                    <p class="mskba-support-chat__source">Страница обращения: <a href="{{ $entry['source_path'] }}" target="_blank" rel="noopener noreferrer">{{ $entry['source_path'] }}</a></p>
                @endif
                <p class="mskba-support-chat__text">{{ $entry['text'] }}</p>
                <time class="mskba-support-chat__date" datetime="{{ $entry['at']?->toIso8601String() }}">{{ $entry['at']?->format('d.m.Y H:i') }}</time>
            </article>
        @empty
            <p class="mskba-support-chat__secondary">Пока нет сообщений.</p>
        @endforelse
    </div>

    @if ($canSend)
        <form class="mskba-support-chat__compose" wire:submit="send">
            <label for="support-live-message-{{ $ticket->id }}" class="mskba-support-chat__name">Ответ пользователю</label>
            <textarea id="support-live-message-{{ $ticket->id }}" class="mskba-support-chat__textarea"
                wire:model="draft" maxlength="2000" required
                placeholder="Напишите ответ пользователю..."></textarea>
            @error('draft') <p role="alert" style="color:#dc2626">{{ $message }}</p> @enderror
            <div class="mskba-support-chat__footer">
                <span class="mskba-support-chat__secondary">До 2000 символов · ответ виден в личном кабинете</span>
                <button type="submit" class="mskba-support-chat__send" wire:loading.attr="disabled" wire:target="send">
                    <span wire:loading.remove wire:target="send">Отправить</span>
                    <span wire:loading wire:target="send">Отправка…</span>
                </button>
            </div>
        </form>
    @else
        <div class="mskba-support-chat__banner">
            {{ $ticket->status === 'closed' ? 'Обращение закрыто. История доступна для чтения.' : 'Отправка сообщений недоступна из-за настроек доступа.' }}
            @if ($ticket->status === 'closed')
                <p>Причина: {{ app(\App\Modules\Notification\Application\Services\SupportTicketLifecycleText::class)->reason($ticket->close_reason, $ticket->close_description) ?? 'Не указана' }}</p>
            @endif
        </div>
    @endif
</div>
