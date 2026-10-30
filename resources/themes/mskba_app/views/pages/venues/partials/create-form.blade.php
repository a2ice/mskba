@php
    $instance = $instance ?? 'venue-page';
    $types = $types ?? \App\Modules\Venue\Domain\Enums\VenueTypeEnum::cases();
    $metros = $metros ?? app(\App\Modules\Location\Application\UseCases\ListMetrostationsHandler::class)->handle();
@endphp
<form method="POST" action="{{ route('venues.store') }}" enctype="multipart/form-data"
    class="app-venue-create" data-mskba-venue-create data-success-url="{{ route('account.venues') }}">
    @csrf
    <p class="app-venue-create__help">Поля со звёздочкой обязательны. После добавления площадку можно дополнить и отправить на модерацию.</p>
    <input type="hidden" name="creation_role" value="contributor">
    <div class="app-venue-create__grid">
        <label class="app-venue-create__field">Название *
            <input name="name" type="text" maxlength="255" minlength="3" required placeholder="Название площадки" value="{{ old('name') }}">
        </label>
        <label class="app-venue-create__field">Тип *
            <select name="type" required>
                <option value="">Выберите тип</option>
                @foreach($types as $type)
                    <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
        </label>
    </div>
    <fieldset class="app-venue-create__address" data-address-suggest data-address-proximity-url="{{ route('venues.proximity-check') }}">
        <legend>Адрес площадки *</legend>
        <div class="app-venue-create__address-actions">
            <button type="button" class="app-player-workspace__secondary" data-address-current-location
                data-address-reverse-url="{{ route('integrations.address-reverse') }}">Я на площадке</button>
            <button type="button" class="app-player-workspace__secondary" data-venue-address-manual>Ввести адрес</button>
        </div>
        <div class="address-suggest__input-wrap">
            <input name="location[raw_address]" type="search" data-address-suggest-input
                data-address-suggest-url="{{ route('integrations.address-suggest') }}"
                value="{{ old('location.raw_address') }}" autocomplete="off" required
                placeholder="Начните вводить адрес и выберите вариант из подсказок">
            <button type="button" class="address-suggest__control" aria-label="Очистить адрес" data-address-clear hidden>×</button>
            <div data-address-suggest-list class="address-suggest__list d-none" role="listbox"></div>
        </div>
        <span class="app-venue-create__metro">Ближайшее метро: <strong data-address-metro-summary>Подставится после выбора адреса</strong></span>
        <span class="app-venue-create__warning" data-address-proximity-warning role="status" hidden></span>
        <span class="app-venue-create__error d-none" data-address-suggest-error role="alert"></span>
        <input type="hidden" name="location[address_selected]" value="" data-address-selected>
        <input type="hidden" name="location[city_id]" value="" data-address-city-id>
        <input type="hidden" name="location[district_id]" value="" data-address-district-id>
        <input type="hidden" name="location[city]" value="" data-address-city>
        <input type="hidden" name="location[street]" value="" data-address-street>
        <input type="hidden" name="location[building]" value="" data-address-building>
        <input type="hidden" name="location[postal_code]" value="" data-address-postal-code>
        <input type="hidden" name="location[latitude]" value="" data-address-latitude>
        <input type="hidden" name="location[longitude]" value="" data-address-longitude>
    </fieldset>
    <select class="app-venue-create__hidden-metro" name="location[metro_station_ids][]" multiple data-address-metro-select tabindex="-1" aria-hidden="true">
        @foreach($metros as $metro)
            <option value="{{ $metro->id }}">{{ $metro->name }}@if($metro->lineName) ({{ $metro->lineName }}) @endif</option>
        @endforeach
    </select>
    <div class="app-venue-create__grid">
        <label class="app-venue-create__field">Залов / зон (по умолчанию 1)
            <input name="court_count" type="number" min="1" max="10" value="{{ old('court_count', 1) }}">
        </label>
        <label class="app-venue-create__field">Колец в каждой зоне (по умолчанию 2)
            <input name="hoops_count" type="number" min="0" max="20" value="{{ old('hoops_count', 2) }}">
        </label>
        <label class="app-venue-create__field">Покрытие (необязательно)
            <select name="surface_type"><option value="">Не указано</option>
                @foreach(\App\Modules\Venue\Domain\Enums\VenueSurfaceTypeEnum::cases() as $surface)
                    <option value="{{ $surface->value }}" @selected(old('surface_type') === $surface->value)>{{ $surface->label() }}</option>
                @endforeach
            </select>
        </label>
        <label class="app-venue-create__field">Условия посещения
            <select name="access_type">
                <option value="unknown">Не знаю</option>
                <option value="free">Бесплатно</option>
                <option value="paid">Платно</option>
            </select>
        </label>
    </div>
    <label class="app-venue-create__field">Фото площадки (необязательно)
        <input type="file" name="photo" accept="image/png,image/jpeg,image/webp">
        <small>До 10 МБ, JPEG/PNG/WebP. При ошибке загрузки площадка останется в черновиках.</small>
    </label>
    <label class="app-venue-create__field">Краткое описание (необязательно)
        <textarea name="short_description" rows="2" maxlength="500" placeholder="Что полезно знать об этой площадке">{{ old('short_description') }}</textarea>
    </label>
    <label class="app-venue-create__field">Подробное описание (необязательно)
        <textarea name="full_description" rows="3" maxlength="10000">{{ old('full_description') }}</textarea>
    </label>
    <div class="app-venue-create__actions">
        <button class="app-player-workspace__primary" type="submit" data-venue-submit>Добавить площадку</button>
        <p role="status" aria-live="polite" data-venue-create-status></p>
    </div>
</form>
