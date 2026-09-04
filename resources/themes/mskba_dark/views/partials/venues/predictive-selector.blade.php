@props([
    'id' => 'venueSelector',
    'name' => 'venue_id',
    'label' => 'Площадка',
    'selectedVenue' => null,
    'searchUrl' => route('venues.search'),
    'confirmedOnly' => false,
    'operationalStatus' => null,
    'startInput' => null,
    'durationInput' => null,
    'mapModal' => null,
    'showFavorites' => false,
    'scopeName' => 'booking_scope',
    'selectedScope' => 'whole',
    'required' => true,
    'showBookingScope' => true,
    'showMetroFilter' => true,
    'metroOptions' => null,
])

@php
    $mapModalId = $mapModal ?: $id.'-map';
    $previewModalId = $id.'-preview';
    $metroFilterId = $id.'MetroFilter';
    $resolvedMetroOptions = $showMetroFilter
        ? ($metroOptions ?? app(\App\Modules\Location\Application\UseCases\ListMetrostationsHandler::class)->handle())
        : collect();
    $selectedId = data_get($selectedVenue, 'id');
    $selectedAddressModel = data_get($selectedVenue, 'location.address');
    $selectedAddress = $selectedVenue
        ? app(\App\Modules\Location\Application\Services\AddressDisplayFormatter::class)->format(
            data_get($selectedVenue, 'raw_address'),
            data_get($selectedAddressModel, 'city'),
            data_get($selectedAddressModel, 'street'),
            data_get($selectedAddressModel, 'building'),
        )
        : null;
    $selectedLabel = $selectedVenue
        ? data_get($selectedVenue, 'name').($selectedAddress ? ' — '.$selectedAddress : '')
        : '';
    $selectedIdentifier = $selectedVenue
        ? data_get($selectedVenue, 'id').'-'.data_get($selectedVenue, 'alias')
        : null;
    $selectedHoopsCount = (int) (data_get($selectedVenue, 'characteristics.hoops_count') ?? 1);
@endphp

<div
    class="venue-selector"
    data-venue-selector
    data-search-url="{{ $searchUrl }}"
    data-confirmed-only="{{ $confirmedOnly ? '1' : '0' }}"
    @if($operationalStatus) data-operational-status="{{ $operationalStatus }}" @endif
    @if($startInput) data-start-input="{{ $startInput }}" @endif
    @if($durationInput) data-duration-input="{{ $durationInput }}" @endif
    data-map-modal="{{ $mapModalId }}"
    data-preview-modal="{{ $previewModalId }}"
    data-required="{{ $required ? '1' : '0' }}"
>
    <label class="form-label" for="{{ $id }}Search">{{ $label }}</label>

    <div class="address-suggest__input-wrap predictive-search__input-wrap venue-selector__input-wrap">
        <input
            id="{{ $id }}Search"
            class="form-control input-predictive predictive-search__input @error($name) is-invalid @enderror"
            type="text"
            value="{{ $selectedLabel }}"
            placeholder="Начните вводить название, улицу, метро или тег..."
            autocomplete="off"
            @if($required) required @endif
            data-venue-selector-input
        >
        <input
            type="hidden"
            name="{{ $name }}"
            value="{{ request()->routeIs('events.wizard') ? old($name, $selectedId) : $selectedId }}"
            data-venue-selector-value
        >
        <button
            class="address-suggest__control predictive-search__control venue-selector__control"
            type="button"
            aria-label="Очистить площадку"
            data-venue-selector-clear
            @if(!$selectedVenue) hidden @endif
        ></button>
        <div
            class="address-suggest__list predictive-search__list venue-selector__list d-none"
            role="listbox"
            data-venue-selector-list
        ></div>
    </div>

    @if($showBookingScope)
    <div class="mt-3" data-venue-booking-scope @if($selectedHoopsCount < 2) hidden @endif>
        <label class="form-label" for="{{ $id }}Scope"><span title="Вся площадка блокирует обе половины. Бронь отдельной половины оставляет вторую доступной для параллельной игры." data-tooltip-icon>Игровая зона</span></label>
        <select class="form-select" id="{{ $id }}Scope" name="{{ $scopeName }}" data-venue-booking-scope-input>
            @foreach(\App\Modules\Event\Domain\Enums\VenueBookingScopeEnum::cases() as $scope)
                <option value="{{ $scope->value }}" @selected(old($scopeName, $selectedScope) === $scope->value)>{{ $scope->label() }}</option>
            @endforeach
        </select>
        <p class="form-text">По умолчанию бронируется вся площадка.</p>
    </div>
    @endif

    <div class="address-suggest__message predictive-search__message text-danger d-none" data-venue-selector-message></div>
    @error($name) <div class="invalid-feedback d-block">{{ $message }}</div> @enderror

    <div class="venue-selector__links mt-2">
        @if($showFavorites)
            <a href="#favorite-venues" class="fc-link js-handler" data-handler="modal" data-modal-action="open" data-modal-target="event-favorite-venues">Избранные площадки</a>
        @endif
        <a
            href="#venue-map"
            class="fc-link js-handler"
            data-handler="modal"
            data-modal-action="open"
            data-modal-target="{{ $mapModalId }}"
            data-venue-map-selector-open
        >На карте</a>
        @if($showMetroFilter)
            <button
                class="fc-link venue-selector__metro-toggle"
                type="button"
                aria-expanded="false"
                aria-controls="{{ $metroFilterId }}Panel"
                data-venue-selector-metro-toggle
            >Выбор метро</button>
        @endif
        <a
            href="#venue-preview"
            class="fc-link js-handler"
            data-handler="modal"
            data-modal-action="open"
            data-modal-target="{{ $previewModalId }}"
            data-preview-url="{{ $selectedIdentifier ? route('venues.preview', $selectedIdentifier) : '' }}"
            data-venue-preview-open
            @if(!$selectedVenue) hidden @endif
        >Посмотреть площадку</a>
    </div>

    @if($showMetroFilter)
        <div
            class="venue-selector__metro-panel mt-2"
            id="{{ $metroFilterId }}Panel"
            data-venue-selector-metro-panel
            hidden
        >
            <label class="form-label" for="{{ $metroFilterId }}">Метро</label>
            <select
                id="{{ $metroFilterId }}"
                class="form-select metro_select venue-selector__metro-select"
                multiple
                data-venue-selector-metro-filter
                data-placeholder="Выберите одну или несколько станций"
            >
                @foreach($resolvedMetroOptions as $metro)
                    <option
                        value="{{ $metro->id }}"
                        data-line-name="{{ $metro->lineName }}"
                        data-line-color="{{ $metro->lineColor ?? '#666666' }}"
                    >{{ $metro->name }}@if($metro->lineName) ({{ $metro->lineName }})@endif</option>
                @endforeach
            </select>
            <p class="form-text">Можно выбрать несколько станций. Поиск и карта покажут площадки рядом хотя бы с одной из них.</p>
        </div>
    @endif
</div>

@component('theme::partials.modal.layout', [
    'id' => $mapModalId,
    'dialogClass' => 'venue-selector-map-modal__dialog',
])
    <h2 class="modal_title" id="modal-title-{{ $mapModalId }}">Выбрать площадку на карте</h2>
    <p class="venue-selector-map__message" data-venue-selector-map-message>Загружаем площадки…</p>
    <div class="venue-selector-map-fallback" data-venue-selector-map-fallback hidden></div>
    <div
        class="venue-selector-map"
        data-venue-selector-map
        data-yandex-map-api-key="{{ config('integrations.yandex.api_key') }}"
        aria-label="Карта площадок"
    ></div>
@endcomponent

@component('theme::partials.modal.layout', [
    'id' => $previewModalId,
    'dialogClass' => 'venue-selector-preview-modal__dialog',
])
    <h2 class="modal_title" id="modal-title-{{ $previewModalId }}" data-venue-preview-title>Площадка</h2>
    <p class="venue-selector-preview__message" data-venue-preview-message>Загружаем информацию…</p>
    <article class="venue-selector-preview" data-venue-preview-content hidden>
        <div class="venue-selector-preview__image-wrap" data-venue-preview-image-wrap hidden>
            <img class="venue-selector-preview__image" src="" alt="" data-venue-preview-image>
        </div>
        <div class="venue-selector-preview__badges">
            <span class="venue-selector-preview__badge" data-venue-preview-type></span>
            <span class="venue-selector-preview__state" data-venue-preview-state></span>
        </div>
        <p class="venue-selector-preview__address" data-venue-preview-address></p>
        <p class="venue-selector-preview__metro" data-venue-preview-metro hidden></p>
        <p class="venue-selector-preview__hours" data-venue-preview-hours></p>
        <p class="venue-selector-preview__description" data-venue-preview-description hidden></p>
        <a class="btn" href="#" target="_blank" rel="noopener" data-venue-preview-page>Открыть площадку</a>
    </article>
@endcomponent