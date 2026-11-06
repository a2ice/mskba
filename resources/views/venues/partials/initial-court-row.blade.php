@php
    $selected = array_map('intval', (array) ($court['amenity_ids'] ?? []));
    $environment = (string) ($court['environment'] ?? '');
@endphp
<fieldset class="venue-court-entry" data-court-entry data-index="{{ $index }}">
    <legend data-court-caption>Зал / корт</legend>
    <div class="venue-court-entry__grid">
        <label>Название *
            <input name="initial_courts[{{ $index }}][name]" required minlength="2" maxlength="120"
                value="{{ $court['name'] ?? '' }}" placeholder="Например: Корт 1">
        </label>
        <label>Тип *
            <select name="initial_courts[{{ $index }}][environment]" required data-court-environment>
                <option value="">Выберите тип</option>
                <option value="indoor" @selected($environment === 'indoor')>Крытый зал</option>
                <option value="outdoor" @selected($environment === 'outdoor')>Уличный корт</option>
            </select>
        </label>
        <label>Количество колец
            <select name="initial_courts[{{ $index }}][hoops_count]">
                <option value="1" @selected((int) ($court['hoops_count'] ?? 2) === 1)>1</option>
                <option value="2" @selected((int) ($court['hoops_count'] ?? 2) === 2)>2</option>
            </select>
        </label>
        <label>Покрытие
            <select name="initial_courts[{{ $index }}][surface_type]">
                <option value="">Не указано</option>
                @foreach(\App\Modules\Venue\Domain\Enums\VenueSurfaceTypeEnum::cases() as $surface)
                    <option value="{{ $surface->value }}" @selected(($court['surface_type'] ?? null) === $surface->value)>{{ $surface->label() }}</option>
                @endforeach
            </select>
        </label>
    </div>
    <fieldset class="venue-court-entry__equipment">
        <legend>Оснащение зала / корта</legend>
        <div class="venue-court-entry__choices">
            @foreach($courtAmenities as $amenity)
                <label data-applicability="{{ $amenity->applies_to }}">
                    <input type="checkbox" name="initial_courts[{{ $index }}][amenity_ids][]"
                        value="{{ $amenity->id }}" @checked(in_array((int) $amenity->id, $selected, true))>
                    {{ $amenity->name }}
                </label>
            @endforeach
        </div>
    </fieldset>
    <button type="button" class="venue-court-entry__remove" data-court-remove>Удалить</button>
</fieldset>
