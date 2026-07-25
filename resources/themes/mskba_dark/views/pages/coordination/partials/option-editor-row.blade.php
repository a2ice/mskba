@php
    $option = $option ?? '';
    $inputName = "options[{$index}]";
@endphp

<div class="coordination-option-row" data-coordination-option-row>
    <div @class([
        'coordination-option-row__fields',
        'coordination-option-row__fields--interval' => $subjectType === 'time_interval',
    ])>
        @switch($subjectType)
            @case('date')
                <input class="form-control" type="date" name="{{ $inputName }}" value="{{ is_scalar($option) ? $option : '' }}" required>
                @break
            @case('time')
                <input class="form-control" type="time" name="{{ $inputName }}" value="{{ is_scalar($option) ? $option : '' }}" required>
                @break
            @case('datetime')
                <input class="form-control" type="datetime-local" name="{{ $inputName }}" value="{{ is_scalar($option) ? $option : '' }}" required>
                @break
            @case('time_interval')
                <input
                    class="form-control"
                    type="time"
                    name="{{ $inputName }}[starts_at]"
                    value="{{ is_array($option) ? ($option['starts_at'] ?? '') : '' }}"
                    aria-label="Начало интервала"
                    required
                >
                <span class="coordination-option-row__separator">—</span>
                <input
                    class="form-control"
                    type="time"
                    name="{{ $inputName }}[ends_at]"
                    value="{{ is_array($option) ? ($option['ends_at'] ?? '') : '' }}"
                    aria-label="Окончание интервала"
                    required
                >
                @break
            @case('venue')
                <select class="form-select" name="{{ $inputName }}" required>
                    <option value="">Выберите площадку</option>
                    @foreach($optionVenues as $venue)
                        <option value="{{ $venue->id }}" @selected((string) $option === (string) $venue->id)>
                            {{ $venue->name }} — {{ $venue->raw_address }}
                        </option>
                    @endforeach
                </select>
                @break
            @default
                <input class="form-control" name="{{ $inputName }}" value="{{ is_scalar($option) ? $option : '' }}" maxlength="255" required>
        @endswitch
    </div>
    <button class="btn btn--secondary btn--sm" type="button" data-coordination-option-remove aria-label="Удалить вариант">×</button>
</div>
