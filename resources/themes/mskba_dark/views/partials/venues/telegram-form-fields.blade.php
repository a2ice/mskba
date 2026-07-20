@php
    $fieldPrefix = $fieldPrefix ?? 'telegramVenue';
    $includeDescriptions = $includeDescriptions ?? false;
@endphp

<input type="hidden" name="telegram_flow" value="1">

<label class="telegram-venue-form__field" for="{{ $fieldPrefix }}Name">
    <span>Название</span>
    <input id="{{ $fieldPrefix }}Name" type="text" name="name" minlength="3" maxlength="255" required>
    <small data-field-error="name"></small>
</label>

<label class="telegram-venue-form__field" for="{{ $fieldPrefix }}Type">
    <span>Тип площадки</span>
    <select id="{{ $fieldPrefix }}Type" name="type" required>
        <option value="">Выберите тип</option>
        @foreach($venueTypes as $type)
            <option value="{{ $type->value }}">{{ $type->label() }}</option>
        @endforeach
    </select>
    <small data-field-error="type"></small>
</label>

<div class="telegram-venue-form__field address-suggest" data-address-suggest>
    <label for="{{ $fieldPrefix }}Address">Адрес</label>
    <div class="address-suggest__input-wrap">
        <input
            id="{{ $fieldPrefix }}Address"
            type="text"
            name="location[raw_address]"
            placeholder="Начните вводить адрес"
            autocomplete="off"
            required
            data-address-suggest-input
            data-address-suggest-url="{{ route('integrations.address-suggest') }}"
        >
        <button class="address-suggest__control" type="button" aria-label="Очистить адрес" data-address-clear hidden></button>
        <div class="address-suggest__list d-none" data-address-suggest-list></div>
    </div>
    <button
        class="address-suggest__location-button telegram-venue-form__location-button"
        type="button"
        data-address-current-location
        data-address-reverse-url="{{ route('integrations.address-reverse') }}"
    >
        Я на площадке
    </button>
    <input type="hidden" name="location[address_selected]" data-address-selected>
    <input type="hidden" name="location[city]" data-address-city>
    <input type="hidden" name="location[street]" data-address-street>
    <input type="hidden" name="location[building]" data-address-building>
    <input type="hidden" name="location[postal_code]" data-address-postal-code>
    <input type="hidden" name="location[latitude]" data-address-latitude>
    <input type="hidden" name="location[longitude]" data-address-longitude>

    <select name="location[metro_station_ids][]" multiple hidden data-address-metro-select>
        @foreach($metros as $metro)
            <option value="{{ $metro->id }}">{{ $metro->name }}</option>
        @endforeach
    </select>

    <div class="address-suggest__message text-danger d-none" data-address-suggest-error></div>
    <small data-field-error="location.raw_address"></small>
    <small data-field-error="location.address_selected"></small>
</div>

<div class="telegram-venue-form__metro">
    <span>Ближайшее метро</span>
    <strong data-telegram-venue-metro>Подставится после выбора адреса</strong>
</div>

@if($includeDescriptions)
    <label class="telegram-venue-form__field" for="{{ $fieldPrefix }}Tags">
        <span>Теги</span>
        <input id="{{ $fieldPrefix }}Tags" type="text" name="tags" maxlength="1000" placeholder="Например: круглосуточно, крытая, бесплатная">
        <small>Разделяйте теги запятыми</small>
        <small data-field-error="tags"></small>
    </label>

    <label class="telegram-venue-form__field" for="{{ $fieldPrefix }}ShortDescription">
        <span>Краткое описание</span>
        <textarea id="{{ $fieldPrefix }}ShortDescription" name="short_description" rows="2" maxlength="500"></textarea>
        <small data-field-error="short_description"></small>
    </label>

    <label class="telegram-venue-form__field" for="{{ $fieldPrefix }}FullDescription">
        <span>Полное описание</span>
        <textarea id="{{ $fieldPrefix }}FullDescription" name="full_description" rows="5" maxlength="10000"></textarea>
        <small data-field-error="full_description"></small>
    </label>
@endif
