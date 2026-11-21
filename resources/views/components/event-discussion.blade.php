@if($chatCanRespond ?? true)
    <div class="venue-play-dialog__rsvp" role="group" aria-label="Ответ на участие">
        <button type="button" class="{{ $secondaryButton }}" data-venue-play-rsvp="confirmed" data-venue-play-attendance>Пойду</button>
        <button type="button" class="{{ $secondaryButton }}" data-venue-play-rsvp="tentative">Подумаю</button>
    </div>
@endif
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
@if($chatShowWall ?? true)
<p class="venue-play-dialog__feedback" data-venue-play-feedback role="status" hidden></p>
<section class="venue-play-dialog__wall" aria-label="Обсуждение мероприятия">
    <ol class="venue-play-dialog__messages mskba-scroll" data-venue-play-messages aria-live="polite"></ol>
    <p class="muted" data-venue-play-wall-empty>Загружаем обсуждение…</p>
    @include('components.chat-composer', [
        'chatComposerId' => ($chatComposerId ?? 'venue-play-message-'.$chatEvent->id),
        'chatComposerLabel' => $chatComposerLabel ?? 'Написать сообщение',
        'chatActions' => [],
    ])
    <p class="muted" data-venue-play-comment-notice hidden>Написать сообщение могут участники мероприятия. <a class="venue-play-dialog__participation-link" href="{{ isset($chatEvent->id) && method_exists($chatEvent,'routeIdentifier') ? route('events.show', $chatEvent->routeIdentifier()) : '#' }}">Участвовать</a></p>
</section>
@endif
