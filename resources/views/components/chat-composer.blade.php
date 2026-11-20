{{-- Shared composer: callers supply permitted actions as id/value/label/optional slot.
     Action handlers belong to the caller; server permissions are still authoritative. --}}
<form data-venue-play-message-form data-chat-composer data-chat-can-post="false" hidden>
    <textarea id="{{ $chatComposerId }}" name="body" maxlength="200" rows="2" required
        aria-label="Написать сообщение" placeholder="Написать сообщение"></textarea>
    <div class="venue-play-dialog__message-footer">
        <small data-venue-play-chars aria-label="Осталось символов">200</small>
        <button type="button" class="venue-play-dialog__edit-cancel" data-venue-play-edit-cancel hidden aria-label="Отменить редактирование" title="Отменить редактирование"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button>
        <details class="chat-actions" data-chat-actions>
            <summary class="chat-actions__toggle" aria-label="Действия с сообщением" title="Действия с сообщением" aria-disabled="true"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg></summary>
            <div class="chat-actions__menu" role="group" aria-label="Действия с сообщением">
                @foreach($chatActions ?? [] as $action)
                    <button type="button" data-chat-action="{{ $action['id'] }}"
                        data-chat-action-value="{{ $action['value'] ?? '' }}"
                        @if(isset($action['slot'])) data-chat-action-slot="{{ $action['slot'] }}" @endif>{{ $action['label'] }}</button>
                @endforeach
                <button type="button" class="chat-actions__danger" data-venue-play-delete data-chat-context-delete hidden>Удалить</button>
            </div>
        </details>
        <button type="submit" class="{{ $primaryButton }} venue-play-dialog__send" aria-label="Отправить сообщение" title="Отправить сообщение">
            <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h15m-6-6 6 6-6 6"/></svg>
        </button>
    </div>
</form>
