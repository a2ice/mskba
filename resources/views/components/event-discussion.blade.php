@if($chatShowRoster ?? true)
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
@endif
<p class="venue-play-dialog__feedback" data-venue-play-feedback role="status" hidden></p>
<section class="venue-play-dialog__wall" aria-label="Обсуждение мероприятия">
    <ol class="venue-play-dialog__messages mskba-scroll" data-venue-play-messages aria-live="polite"></ol>
    <p class="muted" data-venue-play-wall-empty>Загружаем обсуждение…</p>
    @include('components.chat-composer', [
        'chatComposerId' => 'venue-play-message-'.$chatEvent->id,
        'chatActions' => ($chatCanRespond ?? true) ? [
            ['id' => 'event-rsvp', 'value' => 'confirmed', 'slot' => 'attendance', 'label' => 'Пойду'],
            ['id' => 'event-rsvp', 'value' => 'tentative', 'label' => 'Подумаю'],
        ] : [],
    ])
    <p class="muted" data-venue-play-comment-notice hidden>Написать сообщение могут участники и организатор мероприятия.</p>
</section>
