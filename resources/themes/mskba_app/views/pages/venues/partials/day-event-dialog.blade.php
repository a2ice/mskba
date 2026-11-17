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
        <p class="app-venue-day-event-dialog__access" data-venue-day-event-access></p>
        <p data-venue-day-event-description hidden></p>
        <dl>
            <div><dt>Тип</dt><dd data-venue-day-event-type></dd></div>
            <div data-venue-day-event-status-row hidden>
                <dt>Статус</dt><dd data-venue-day-event-status></dd>
            </div>
            <div data-venue-day-event-count-row hidden>
                <dt>Участники</dt><dd data-venue-day-event-count></dd>
            </div>
            <div data-venue-day-event-organizer-row hidden>
                <dt>Организатор</dt><dd>
                    <button type="button" class="app-venue-day-event-dialog__contact"
                        data-venue-day-event-message data-direct-message-open hidden>
                        <span data-venue-day-event-organizer></span>
                    </button>
                    <span data-venue-day-event-organizer-fallback></span>
                </dd>
            </div>
        </dl>
        <p class="app-venue-day-event-dialog__private-note" data-venue-day-event-private-note hidden>
            Подробности доступны только приглашённым участникам.
        </p>
        <div class="app-venue-day-event-dialog__participation" data-venue-day-event-participation hidden>
            <p role="status" data-venue-day-event-join-state></p>
            @auth
                <form method="POST" data-venue-day-event-join-form hidden>
                    @csrf
                    <input type="hidden" name="_method" value="PATCH" data-venue-day-event-join-method>
                    <input type="hidden" name="status" value="confirmed">
                    <button class="button primary" type="submit" data-venue-day-event-join-label>
                        Присоединиться
                    </button>
                </form>
                <form method="POST" data-venue-day-event-tentative-form hidden>
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="tentative">
                    <button class="button secondary" type="submit">Думаю</button>
                </form>
            @else
                <a class="button primary" data-venue-day-event-guest-join hidden>
                    Присоединиться
                </a>
            @endauth
        </div>
        <a class="button secondary" data-venue-day-event-link hidden>Страница мероприятия</a>
    </div>
</dialog>
