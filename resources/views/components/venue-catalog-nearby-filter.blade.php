@php
    $nearbyScope = 'venue-nearby-'.($scope ?? 'app');
    $nearbyActive = isset($filters['latitude'], $filters['longitude'], $filters['radius_km']);
    $isApp = app(\App\Presentation\Theming\ThemeResolver::class)->active() === 'mskba_app';
    $secondary = $isApp ? 'button secondary' : 'btn btn--secondary';
    $primary = $isApp ? 'button primary' : 'btn btn--primary';
@endphp
<div data-nearby-filter class="venue-nearby-filter">
    <label class="check-label venue-nearby-filter__toggle">
        <input type="checkbox" data-nearby-toggle aria-controls="{{ $nearbyScope }}-options" @checked($nearbyActive)>
        <span>Рядом со мной</span>
    </label>
    <div data-nearby-options id="{{ $nearbyScope }}-options" @if(!$nearbyActive) hidden @endif>
        <input type="hidden" name="latitude" data-nearby-lat value="{{ $filters['latitude'] ?? '' }}" @disabled(!$nearbyActive)>
        <input type="hidden" name="longitude" data-nearby-lon value="{{ $filters['longitude'] ?? '' }}" @disabled(!$nearbyActive)>
        <label class="app-player-workspace__field app-player-workspace__range-field" for="{{ $nearbyScope }}-range">
            <span class="app-player-workspace__range-heading">Радиус, км <output data-nearby-radius-value for="{{ $nearbyScope }}-range">{{ $filters['radius_km'] ?? 3 }}</output></span>
            <input id="{{ $nearbyScope }}-range" type="range" name="radius_km" min="1" max="10" step="1" value="{{ $filters['radius_km'] ?? 3 }}" data-nearby-radius @disabled(!$nearbyActive)>
            <span class="app-player-workspace__range-bounds" aria-hidden="true"><span>1</span><span>10</span></span>
        </label>
        <button type="button" class="{{ $secondary }}" data-nearby-change>Изменить точку</button>
    </div>
    <p data-nearby-status role="status" hidden></p>
    <dialog class="mskba-modal venue-nearby-dialog {{ !$isApp ? 'venue-nearby-dialog--dark' : '' }}" data-nearby-dialog aria-labelledby="{{ $nearbyScope }}-title">
        <header class="mskba-modal__header venue-nearby-dialog__header">
            <h2 id="{{ $nearbyScope }}-title">Выберите местоположение</h2>
            <button type="button" class="icon-button venue-nearby-dialog__close" data-nearby-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button>
        </header>
        <div class="mskba-modal__body mskba-scroll venue-nearby-dialog__body">
            <div class="venue-nearby-dialog__actions">
                <button type="button" class="{{ $secondary }}" data-nearby-mode="address" aria-pressed="true">По адресу</button>
                <button type="button" class="{{ $secondary }}" data-nearby-mode="map" aria-pressed="false">На карте</button>
            </div>
            <div data-nearby-address-panel data-address-suggest>
                <label for="{{ $nearbyScope }}-address">Адрес</label>
                <div class="address-suggest__input-wrap">
                    <input type="search" id="{{ $nearbyScope }}-address" data-address-suggest-input data-address-suggest-url="{{ route('integrations.address-suggest') }}" autocomplete="off" placeholder="Начните вводить адрес">
                    <button type="button" class="address-suggest__control" data-address-clear hidden aria-label="Очистить адрес">×</button>
                    <div data-address-suggest-list class="address-suggest__list d-none" role="listbox"></div>
                </div>
                <p data-address-suggest-error class="d-none" role="alert"></p>
                <input type="hidden" data-address-selected><input type="hidden" data-address-latitude><input type="hidden" data-address-longitude>
            </div>
            <div data-nearby-map-panel hidden>
                <p>Нажмите на карту, чтобы выбрать точку поиска.</p>
                <div data-nearby-map class="venue-nearby-dialog__map" data-map-api-key="{{ config('integrations.yandex.api_key') }}"></div>
            </div>
            <p role="status" data-nearby-dialog-status hidden></p>
        </div>
        <footer class="mskba-modal__footer venue-nearby-dialog__footer">
            <button type="button" class="{{ $primary }}" data-nearby-confirm disabled>Выбрать</button>
        </footer>
    </dialog>
</div>
