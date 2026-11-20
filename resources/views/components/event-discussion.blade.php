<section class="venue-play-dialog__roster" aria-label="Участники">
    <h4 data-venue-play-roster-heading>Участники</h4>
    <div class="venue-play-dialog__roster-surface" data-venue-play-roster-surface aria-busy="false">
        <ul class="venue-play-dialog__participants mskba-scroll" data-venue-play-participants aria-label="Список участников"></ul>
        <p class="muted" data-venue-play-roster-empty>Загружаем участников…</p>
        <span class="image-upload-loading" data-venue-play-roster-loading role="status" aria-label="Обновляем участников" hidden>
            <span class="image-upload-loading__spinner" aria-hidden="true"></span>
        </span>
    </div>
    <button type="button" class="{{ $secondaryButton }}" data-venue-play-roster-retry hidden>Повторить загрузку</button>
</section>
@if($chatCanRespond ?? true)
    <div class="venue-play-dialog__rsvp" role="group" aria-label="Ответ на участие">
        <button type="button" class="{{ $secondaryButton }}" data-venue-play-rsvp="confirmed" data-venue-play-attendance>Пойду</button>
        <button type="button" class="{{ $secondaryButton }}" data-venue-play-rsvp="tentative">Подумаю</button>
    </div>
@endif
<p class="venue-play-dialog__feedback" data-venue-play-feedback role="status" hidden></p>
<section class="venue-play-dialog__wall" aria-label="Обсуждение мероприятия">
    <ol class="venue-play-dialog__messages mskba-scroll" data-venue-play-messages aria-live="polite"></ol>
    <p class="muted" data-venue-play-wall-empty>Загружаем обсуждение…</p>
    <form data-venue-play-message-form hidden>
        <textarea id="venue-play-message-{{ $chatEvent->id }}" name="body" maxlength="200" rows="2" required
            aria-label="Написать сообщение" placeholder="Написать сообщение"></textarea>
        <div class="venue-play-dialog__message-footer">
            <small data-venue-play-chars aria-label="Осталось символов">200</small>
            <button type="button" class="venue-play-dialog__edit-cancel" data-venue-play-edit-cancel hidden aria-label="Отменить редактирование" title="Отменить редактирование"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button>
            <button type="button" class="venue-play-dialog__send venue-play-dialog__delete" data-venue-play-delete hidden aria-label="Удалить сообщение" title="Удалить сообщение"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6l-12 12M6 6l12 12"/></svg></button>
            <button type="submit" class="{{ $primaryButton }} venue-play-dialog__send" aria-label="Отправить сообщение" title="Отправить сообщение">
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h15m-6-6 6 6-6 6"/></svg>
            </button>
        </div>
    </form>
    <p class="muted" data-venue-play-comment-notice hidden>Написать сообщение могут участники и организатор мероприятия.</p>
</section>
