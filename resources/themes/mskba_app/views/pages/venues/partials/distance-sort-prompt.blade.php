<dialog class="mskba-modal venue-nearby-dialog" data-venue-distance-prompt
    data-location-url="{{ route('venues.distance-location') }}"
    data-auto-prompt="0"
    data-location-available="{{ $venueDistanceAvailable ? '1' : '0' }}"
    data-prompt-answered="{{ $venueDistanceAnswered ? '1' : '0' }}"
    data-distance-selected="{{ ($filters['sort'] ?? '') === 'distance' ? '1' : '0' }}"
    aria-labelledby="venue-distance-prompt-title">
    <header class="mskba-modal__header venue-nearby-dialog__header">
        <h2 id="venue-distance-prompt-title">Отсортировать площадки в порядке удаленности от меня?</h2>
    </header>
    <div class="mskba-modal__body mskba-scroll venue-nearby-dialog__body" data-distance-status hidden>
        <p role="status">Не удалось сохранить выбор. Попробуйте еще раз.</p>
    </div>
    <footer class="mskba-modal__footer venue-nearby-dialog__footer">
        <button type="button" class="button secondary" data-distance-manual>Сам решу-у</button>
        <button type="button" class="button primary" data-distance-accept>Да, отсортировать</button>
    </footer>
</dialog>

<p data-venue-distance-error role="status" hidden></p>
