@php
    $errorBag = $vacancy ? 'hiring'.$vacancy->id : 'createHiring';
    $usesOldInput = $errors->getBag($errorBag)->isNotEmpty();
    $fieldValue = static fn (string $key, mixed $default = null): mixed => $usesOldInput ? old($key, $default) : $default;
    $selectedPositions = $fieldValue('positions', $vacancy?->positions ?? []);
    $selectedGender = $fieldValue('gender', $vacancy?->gender?->value);
@endphp
<div class="row g-3">
    <div class="col-12 col-md-4">
        <label class="form-label" for="{{ $prefix }}-spots-total">Количество игроков</label>
        <input id="{{ $prefix }}-spots-total" class="form-control" type="number" name="spots_total" min="1" max="100" required value="{{ $fieldValue('spots_total', $vacancy?->spots_total ?? 1) }}">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label" for="{{ $prefix }}-experience">Минимальный опыт, лет</label>
        <input id="{{ $prefix }}-experience" class="form-control" type="number" name="minimum_experience_years" min="0" max="60" value="{{ $fieldValue('minimum_experience_years', $vacancy?->minimum_experience_years) }}" placeholder="Не важен">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label" for="{{ $prefix }}-gender">Пол игрока</label>
        <select id="{{ $prefix }}-gender" class="form-select" name="gender">
            <option value="">Не важен</option>
            @foreach($genders as $gender)<option value="{{ $gender->value }}" @selected($selectedGender === $gender->value)>{{ $gender->label() }}</option>@endforeach
        </select>
    </div>
    <fieldset class="col-12">
        <legend class="form-label">Амплуа</legend>
        <div class="d-flex flex-wrap gap-3">
            @foreach($playerPositions as $position)
                <label class="form-check"><input class="form-check-input" type="checkbox" name="positions[]" value="{{ $position->value }}" @checked(in_array($position->value, $selectedPositions, true))><span class="form-check-label">{{ $position->label() }}</span></label>
            @endforeach
        </div>
        <p class="form-hint mt-2 mb-0">Можно выбрать несколько амплуа или не выирать ни одного.</p>
    </fieldset>
    <div class="col-12">
        <label class="form-label" for="{{ $prefix }}-description">Дополнительное описание</label>
        <textarea id="{{ $prefix }}-description" class="form-control" name="description" rows="3" maxlength="2000" placeholder="Например: ищем игрока на регулярные вечерние тренировки">{{ $fieldValue('description', $vacancy?->description) }}</textarea>
    </div>
</div>
@error('spots_total', $errorBag)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
@error('positions', $errorBag)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
@error('positions.*', $errorBag)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
@error('minimum_experience_years', $errorBag)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
@error('gender', $errorBag)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
@error('description', $errorBag)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
