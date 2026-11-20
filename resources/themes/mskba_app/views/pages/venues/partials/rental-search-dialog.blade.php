@php
    $availabilitySearchCourts = collect($venue->courts)->map(fn ($court) => ['id' => $court['id'], 'name' => $court['name']])->values()->all();
@endphp
<dialog class="mskba-modal app-rental-search-dialog" data-rental-search-dialog
    data-venue-id="{{ $venue->id }}"
    data-availability-courts='@json($availabilitySearchCourts, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG)'
    data-courts='@json($rentalSearchCourts, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG)'
    data-lead="{{ $rentalSearchPolicy['minimum_lead_time_minutes'] ?? 0 }}"
    data-step="{{ $rentalSearchPolicy['time_step_minutes'] ?? 30 }}"
    aria-labelledby="rental-search-title">
    <header class="mskba-modal__header">
        <h2 id="rental-search-title">Арендовать площадку</h2>
        <button type="button" class="icon-button" data-rental-search-close aria-label="Закрыть">
            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M18 6l-12 12M6 6l12 12"/></svg>
        </button>
    </header>
    <form class="mskba-wizard" data-rental-search-form>
        <div class="mskba-wizard__progress"><progress value="1" max="2" aria-label="Прогресс мастера"></progress><span>ШАГ 1 / 2</span></div>
        <div class="mskba-modal__body mskba-scroll mskba-wizard__body">
            <h3 class="wizard-step-title">Когда хочешь играть?</h3>
            <p class="wizard-step-hint">Укажи желаемую дату и время или задай диапазон. Время московское.</p>
            <div class="wizard-step">
                <div class="form-row">
                    <div class="field-group"><label for="rental-search-from">Дата</label>
                        <input id="rental-search-from" type="date" data-rental-from required></div>
                    <div class="field-group"><label for="rental-search-start">Время от</label>
                        <input id="rental-search-start" type="time" data-rental-from-time required></div>
                </div>
                <label class="check-label"><input type="checkbox" data-rental-range aria-controls="rental-search-end">Диапазон</label>
                <div class="form-row" id="rental-search-end" data-rental-end-fields hidden>
                    <div class="field-group"><label for="rental-search-to">Дата до</label>
                        <input id="rental-search-to" type="date" data-rental-to disabled required></div>
                    <div class="field-group"><label for="rental-search-finish">Время до</label>
                        <input id="rental-search-finish" type="time" data-rental-to-time disabled required></div>
                </div>
                <p class="mskba-wizard__form-error" data-rental-search-error role="alert" hidden></p>
            </div>
        </div>
        <footer class="mskba-modal__footer mskba-wizard__footer"><div class="wizard-actions">
            <button type="button" class="button secondary" disabled>Назад</button>
            <button type="submit" class="button primary wizard-next" data-rental-search-next>Далее</button>
        </div></footer>
    </form>
</dialog>
