<dialog class="app-venue-overlay app-venue-day-event-dialog" data-venue-day-event-dialog
    aria-labelledby="app-venue-day-event-title">
    <div class="app-venue-overlay__header">
        <h2 id="app-venue-day-event-title" data-venue-day-event-title>Мероприятие</h2>
        <button type="button" data-venue-overlay-close aria-label="Закрыть">
            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 5L19 19M19 5L5 19"/></svg>
        </button>
    </div>
    <div class="app-venue-day-event-dialog__body">
        <p class="app-venue-day-event-dialog__time" data-venue-day-event-time></p>
        <p data-venue-day-event-description hidden></p>
        <dl>
            <div><dt>Тип</dt><dd data-venue-day-event-type></dd></div>
            <div><dt>Статус</dt><dd data-venue-day-event-status></dd></div>
            <div><dt>Участники</dt><dd data-venue-day-event-count></dd></div>
            <div><dt>Организатор</dt><dd>
                <button type="button" class="app-venue-day-event-dialog__contact"
                    data-venue-day-event-message data-direct-message-open hidden>
                    <span data-venue-day-event-organizer></span>
                </button>
                <span data-venue-day-event-organizer-fallback></span>
            </dd></div>
        </dl>
        <a class="button secondary" data-venue-day-event-link>Страница мероприятия</a>
    </div>
</dialog>
