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
])

@php
    $mapModalId = $mapModal ?: $id.'-map';
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
>
    <label class="form-label" for="{{ $id }}">{{ $label }}</label>
    <select
        id="{{ $id }}"
        class="form-select {{ $errors->has($name) ? 'is-invalid' : '' }}"
        name="{{ $name }}"
        required
        data-venue-selector-select
        data-placeholder="Начните вводить название, улицу, метро или тег..."
    >
        @if($selectedVenue)
            <option value="{{ $selectedVenue->id }}" selected>
                {{ $selectedVenue->name }}{{ $selectedVenue->raw_address ? ' — '.$selectedVenue->raw_address : '' }}
            </option>
        @endif
    </select>
    @error($name) <div class="invalid-feedback">{{ $message }}</div> @enderror

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
    </div>
</div>

@component('theme::partials.modal.layout', ['id' => $mapModalId])
    <h2 class="modal_title" id="modal-title-{{ $mapModalId }}">Выбрать площадку на карте</h2>
    <p class="venue-selector-map__message" data-venue-selector-map-message>Загружаем площадки…</p>
    <div
        class="venue-selector-map"
        data-venue-selector-map
        data-yandex-map-api-key="{{ config('integrations.yandex.api_key') }}"
        aria-label="Карта площадок"
    ></div>
@endcomponent
