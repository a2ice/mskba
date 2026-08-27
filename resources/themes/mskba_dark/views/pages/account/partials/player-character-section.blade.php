@php
    use App\Modules\Identity\Domain\Support\PlayerCharacterAppearanceOptions;

    $currentHeight = old('height_cm', $profile?->height_cm);
    $currentWeight = old('weight_kg', $profile?->weight_kg);
    $currentBodyType = old('body_type', $profile?->body_type?->value);
    $characterHeightCm = $currentHeight !== null && $currentHeight !== '' ? (int) $currentHeight : null;
    $characterWeightKg = $currentWeight !== null && $currentWeight !== '' ? (int) $currentWeight : null;

    $profileGender = PlayerCharacterAppearanceOptions::normalizeGender($user->profile?->gender?->value);
    $storedCharacter = is_array($profile?->extra['character'] ?? null)
        ? $profile->extra['character']
        : [];
    $characterDefaults = PlayerCharacterAppearanceOptions::defaults($profileGender);
    $character = array_merge($characterDefaults, $storedCharacter);
    $character['gender'] = $profileGender;

    $characterGender = $profileGender;
    $characterSkinTone = old('character.skin_tone', $character['skin_tone']);
    $characterHairstyle = old('character.hairstyle', $character['hairstyle']);
    $characterHairColor = old('character.hair_color', $character['hair_color']);
    $characterFacialHair = old('character.facial_hair', $character['facial_hair']);
    $characterUniformKit = old('character.uniform_kit', $character['uniform_kit']);

    if (! in_array($characterHairstyle, PlayerCharacterAppearanceOptions::hairstylesForGender($characterGender), true)) {
        $characterHairstyle = $characterDefaults['hairstyle'];
    }

    if ($characterGender === 'female') {
        $characterFacialHair = 'none';
    }

    $skinTones = [
        'porcelain' => ['label' => 'Очень светлый', 'color' => '#f1c7a9'],
        'light' => ['label' => 'Светлый', 'color' => '#ddb08e'],
        'warm' => ['label' => 'Тёплый', 'color' => '#bd8360'],
        'tan' => ['label' => 'Загорелый', 'color' => '#9c6749'],
        'brown' => ['label' => 'Тёмный', 'color' => '#704731'],
        'deep' => ['label' => 'Очень тёмный', 'color' => '#432a22'],
    ];
    $hairColors = [
        'black' => ['label' => 'Чёрный', 'color' => '#171513'],
        'dark_brown' => ['label' => 'Тёмно-коричневый', 'color' => '#3a271f'],
        'brown' => ['label' => 'Коричневый', 'color' => '#694733'],
        'blond' => ['label' => 'Светлый', 'color' => '#c9aa70'],
        'ginger' => ['label' => 'Рыжий', 'color' => '#9a4c2c'],
        'gray' => ['label' => 'Седой', 'color' => '#8c8b87'],
    ];
    $hairstyles = [
        'male_bald' => ['label' => 'Без волос', 'gender' => 'male'],
        'male_buzz' => ['label' => 'Ёжик', 'gender' => 'male'],
        // Keep the persisted key for backwards compatibility; the authored
        // MakeHuman asset is visually a regular short hairstyle, not a fade.
        'male_fade' => ['label' => 'Короткая', 'gender' => 'male'],
        'male_short' => ['label' => 'Короткая', 'gender' => 'male'],
        'male_curls' => ['label' => 'Кудри', 'gender' => 'male'],
        'female_ponytail' => ['label' => 'Хвост', 'gender' => 'female'],
        'female_bob' => ['label' => 'Боб', 'gender' => 'female'],
        'female_long' => ['label' => 'Длинные', 'gender' => 'female'],
        'female_curls' => ['label' => 'Кудри', 'gender' => 'female'],
        'female_braids' => ['label' => 'Косы', 'gender' => 'female'],
    ];
    $facialHairStyles = [
        'none' => 'Без бороды',
        'stubble' => 'Щетина',
        'mustache' => 'Усы',
        'goatee' => 'Эспаньолка',
        'short_beard' => 'Короткая',
        'full_beard' => 'Полная',
    ];
    $authoredHairstyles = ['male_bald', 'male_fade', 'female_ponytail'];
    $authoredFacialHairStyles = ['none', 'short_beard'];

    if (! in_array($characterHairstyle, $authoredHairstyles, true)) {
        $characterHairstyle = $characterDefaults['hairstyle'];
    }

    if (! in_array($characterFacialHair, $authoredFacialHairStyles, true)) {
        $characterFacialHair = 'none';
    }
    $playerTeams = $playerTeams ?? collect();
    $defaultPlayerTeam = $playerTeams->first();
    $defaultUniformPrimary = data_get($defaultPlayerTeam?->colors, 'home_primary');
    $defaultUniformAccent = data_get($defaultPlayerTeam?->colors, 'home_secondary');
@endphp

<section class="account-player-profile__section account-player-character-section">
    <div>
        <h3 class="h4 mb-1">Характеристики игрока</h3>
        <p class="text-muted mb-0">Основные физические данные, игровой образ и баскетбольная специализация.</p>
    </div>

    <div class="account-player-character-layout">
        <div class="account-player-character-visual">
            <div
                class="account-player-character-stage"
                data-player-character-stage
                data-gender="{{ $characterGender }}"
                data-height="{{ $characterHeightCm ?? '' }}"
                data-weight="{{ $characterWeightKg ?? '' }}"
                data-body-type="{{ $currentBodyType ?: 'unspecified' }}"
                data-skin-tone="{{ $characterSkinTone }}"
                data-hairstyle="{{ $characterHairstyle }}"
                data-hair-color="{{ $characterHairColor }}"
                data-facial-hair="{{ $characterFacialHair }}"
                data-uniform-kit="{{ $characterUniformKit }}"
                data-has-height="{{ $characterHeightCm !== null ? 'true' : 'false' }}"
                data-three-status="idle"
                role="group"
                aria-label="Персонаж игрока на шкале роста"
            >
                <div class="account-player-character-stage__width" aria-hidden="true">
                    <span></span>
                    <strong>200 см</strong>
                    <span></span>
                </div>

                <div class="account-player-character-stage__plot" data-player-character-plot>
                    <div class="account-player-character-stage__scale" aria-hidden="true">
                        @foreach([250, 200, 150, 100, 50, 0] as $scaleHeight)
                            <span style="--player-scale-y: {{ (250 - $scaleHeight) / 2.5 }}%;">
                                {{ $scaleHeight }}
                            </span>
                        @endforeach
                    </div>

                    <div class="account-player-character-stage__axis" aria-hidden="true"></div>
                    <div class="account-player-character-three" data-player-character-three></div>

                    <button
                        type="button"
                        class="account-player-character-stage__height-marker"
                        data-player-character-height-marker
                        aria-expanded="false"
                        aria-label="{{ $characterHeightCm !== null ? $characterHeightCm.' см' : 'Рост не указан' }}"
                        hidden
                    >
                        <span data-player-character-height-label>
                            {{ $characterHeightCm !== null ? $characterHeightCm.' см' : 'Рост не указан' }}
                        </span>
                    </button>

                    <div class="account-player-character-stage__floor" aria-hidden="true"></div>
                </div>
            </div>

            <p class="account-player-character-error" data-player-character-error aria-live="polite" hidden></p>
        </div>

        <div class="account-player-character-controls">
            <div class="account-player-profile__grid">
                <div class="form-group field account-player-profile__field">
                    <label for="player-height">Рост, см</label>
                    <select
                        id="player-height"
                        class="form-select"
                        name="height_cm"
                        data-player-character-input="height"
                    >
                        <option value="">Не указан</option>
                        @for($height = 150; $height <= 220; $height++)
                            <option value="{{ $height }}" @selected((string) $currentHeight === (string) $height)>{{ $height }} см</option>
                        @endfor
                    </select>
                    @error('height_cm') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>

                <div class="form-group field account-player-profile__field">
                    <label for="player-weight">Вес, кг</label>
                    <select
                        id="player-weight"
                        class="form-select"
                        name="weight_kg"
                        data-player-character-input="weight"
                    >
                        <option value="">Не указан</option>
                        @for($weight = 40; $weight <= 140; $weight++)
                            <option value="{{ $weight }}" @selected($currentWeight !== null && (int) $currentWeight === $weight)>{{ $weight }} кг</option>
                        @endfor
                    </select>
                    @error('weight_kg') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>

                <div class="form-group field account-player-profile__field">
                    <label for="player-body-type">Сложение</label>
                    <select
                        id="player-body-type"
                        class="form-select"
                        name="body_type"
                        data-player-character-input="body-type"
                    >
                        <option value="">Не указано</option>
                        @foreach($playerBodyTypes as $bodyType)
                            <option value="{{ $bodyType->value }}" @selected($currentBodyType === $bodyType->value)>{{ $bodyType->label() }}</option>
                        @endforeach
                    </select>
                    @error('body_type') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>

                <div class="form-group field account-player-profile__field">
                    <label for="player-experience-year">Играю с</label>
                    <select id="player-experience-year" class="form-select" name="experience_started_year">
                        <option value="">Не указано</option>
                        @for($year = now()->year - 10; $year >= now()->year - 50; $year--)
                            <option value="{{ $year }}" @selected((string) old('experience_started_year', $profile?->experience_started_year) === (string) $year)>{{ $year }}</option>
                        @endfor
                    </select>
                    @error('experience_started_year') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="account-player-character-configurator" data-player-character-configurator>
                <div class="account-player-character-configurator__heading">
                    <div>
                        <span class="eyebrow">Персонаж</span>
                        <h4>Соберите свой игровой образ</h4>
                        <span class="account-player-character-configurator__profile-gender">
                            <i></i>
                            Пол берётся из профиля: {{ $characterGender === 'female' ? 'женский' : 'мужской' }}
                        </span>
                    </div>
                    @if($playerTeams->isNotEmpty())
                        <label class="account-player-character-configurator__team-selector">
                            <span>Команда</span>
                            <select class="form-select" data-player-character-team>
                                @foreach($playerTeams as $team)
                                    <option
                                        value="{{ $team->id }}"
                                        data-team-name="{{ $team->name }}"
                                        data-uniform-primary="{{ data_get($team->colors, 'home_primary') }}"
                                        data-uniform-accent="{{ data_get($team->colors, 'home_secondary') }}"
                                    >{{ $team->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                </div>

                <div class="account-player-character-configurator__group">
                    <span class="account-player-character-configurator__label">Тон кожи</span>
                    <div class="account-player-character-configurator__swatches">
                        @foreach($skinTones as $value => $tone)
                            <button
                                type="button"
                                class="account-player-character-configurator__swatch"
                                style="--character-swatch: {{ $tone['color'] }};"
                                data-player-character-choice="skin-tone"
                                data-value="{{ $value }}"
                                aria-label="{{ $tone['label'] }}"
                                title="{{ $tone['label'] }}"
                                aria-pressed="{{ $characterSkinTone === $value ? 'true' : 'false' }}"
                            ></button>
                        @endforeach
                    </div>
                    <input type="hidden" name="character[skin_tone]" value="{{ $characterSkinTone }}" data-player-character-field="skin-tone">
                    @error('character.skin_tone') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>

                <div class="account-player-character-configurator__group">
                    <span class="account-player-character-configurator__label">Причёска</span>
                    <div class="account-player-character-configurator__chips" data-player-character-hairstyles>
                        @foreach($hairstyles as $value => $hairstyle)
                            @continue(! in_array($value, $authoredHairstyles, true))
                            <button
                                type="button"
                                class="account-player-character-configurator__chip"
                                data-player-character-choice="hairstyle"
                                data-character-gender="{{ $hairstyle['gender'] }}"
                                data-value="{{ $value }}"
                                aria-pressed="{{ $characterHairstyle === $value ? 'true' : 'false' }}"
                                @if($hairstyle['gender'] !== $characterGender) hidden @endif
                            >{{ $hairstyle['label'] }}</button>
                        @endforeach
                    </div>
                    <input type="hidden" name="character[hairstyle]" value="{{ $characterHairstyle }}" data-player-character-field="hairstyle">
                    @error('character.hairstyle') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>

                <div class="account-player-character-configurator__group">
                    <span class="account-player-character-configurator__label">Цвет волос</span>
                    <div class="account-player-character-configurator__swatches">
                        @foreach($hairColors as $value => $tone)
                            <button
                                type="button"
                                class="account-player-character-configurator__swatch account-player-character-configurator__swatch--hair"
                                style="--character-swatch: {{ $tone['color'] }};"
                                data-player-character-choice="hair-color"
                                data-value="{{ $value }}"
                                aria-label="{{ $tone['label'] }}"
                                title="{{ $tone['label'] }}"
                                aria-pressed="{{ $characterHairColor === $value ? 'true' : 'false' }}"
                            ></button>
                        @endforeach
                    </div>
                    <input type="hidden" name="character[hair_color]" value="{{ $characterHairColor }}" data-player-character-field="hair-color">
                    @error('character.hair_color') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>

                <div class="account-player-character-configurator__group" data-player-character-facial-hair-group @if($characterGender === 'female') hidden @endif>
                    <span class="account-player-character-configurator__label">Усы и борода</span>
                    <div class="account-player-character-configurator__chips">
                        @foreach($facialHairStyles as $value => $label)
                            @continue(! in_array($value, $authoredFacialHairStyles, true))
                            <button
                                type="button"
                                class="account-player-character-configurator__chip"
                                data-player-character-choice="facial-hair"
                                data-value="{{ $value }}"
                                aria-pressed="{{ $characterFacialHair === $value ? 'true' : 'false' }}"
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                    @error('character.facial_hair') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <input type="hidden" name="character[facial_hair]" value="{{ $characterGender === 'female' ? 'none' : $characterFacialHair }}" data-player-character-field="facial-hair">

                <div class="account-player-character-configurator__group account-player-character-configurator__group--uniform">
                    <div class="account-player-character-configurator__group-heading">
                        <span class="account-player-character-configurator__label">Форма</span>
                        <span>примерка</span>
                    </div>
                    @if($defaultPlayerTeam)
                        <div class="account-player-character-configurator__team-kit">
                            <span
                                class="account-player-character-configurator__jersey"
                                style="--kit-primary: {{ $defaultUniformPrimary ?: '#555b60' }}; --kit-accent: {{ $defaultUniformAccent ?: '#08090a' }};"
                                data-player-character-team-kit-preview
                            ><i></i></span>
                            <span>
                                <strong data-player-character-team-name>{{ $defaultPlayerTeam->name }}</strong>
                                <small>Домашние цвета команды</small>
                            </span>
                        </div>
                    @else
                        <p class="account-player-character-configurator__future">
                            Вступите в команду как игрок, чтобы примерить её домашние цвета.
                        </p>
                    @endif
                    <p class="account-player-character-configurator__future">
                        Если цвет не задан в настройках команды, соответствующая часть формы остаётся нейтральной.
                    </p>
                    <input type="hidden" name="character[uniform_kit]" value="{{ $characterUniformKit }}" data-player-character-field="uniform-kit">
                    @error('character.uniform_kit') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
            </div>

            <fieldset class="account-player-profile__positions account-player-character-controls__positions">
                <legend>Амплуа</legend>
                <p class="text-muted">Можно выбрать несколько позиций.</p>
                <div class="account-player-profile__position-grid">
                    @foreach($playerPositions as $position)
                        @include('theme::partials.forms.toggle', [
                            'id' => 'player-position-'.$position->value,
                            'name' => 'positions[]',
                            'checked' => in_array($position->value, $selectedPositions, true),
                            'title' => $position->label(),
                            'wrapperClass' => 'account-player-profile__position',
                            'value' => $position->value,
                            'includeHiddenInput' => false,
                        ])
                    @endforeach
                </div>
                @error('positions') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                @error('positions.*') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </fieldset>
        </div>
    </div>
</section>
