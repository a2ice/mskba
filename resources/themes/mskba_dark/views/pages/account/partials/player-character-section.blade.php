@php
    use App\Modules\Identity\Application\Services\PlayerCharacterRenderService;
    use App\Modules\Identity\Domain\Support\PlayerCharacterAppearanceOptions;

    $currentHeight = old('height_cm', $profile?->height_cm);
    $currentWeight = old('weight_kg', $profile?->weight_kg);
    $currentBodyType = old('body_type', $profile?->body_type?->value);
    $characterHeightCm = $currentHeight !== null && $currentHeight !== '' ? (int) $currentHeight : null;

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
    $characterPiercings = array_values((array) old('character.piercings', $character['piercings'] ?? []));
    $characterTattoos = array_values((array) old('character.tattoos', $character['tattoos'] ?? []));
    $characterTattooNote = old('character.tattoo_note', $character['tattoo_note'] ?? '');
    $characterUniformKit = $character['uniform_kit'] ?? 'mskba_home';
    $hasFaceReference = filled($character['face_photo_path'] ?? null);

    if (! in_array($characterHairstyle, PlayerCharacterAppearanceOptions::hairstylesForGender($characterGender), true)) {
        $characterHairstyle = $characterDefaults['hairstyle'];
    }

    if ($characterGender === 'female') {
        $characterFacialHair = 'none';
    }

    $renderState = app(PlayerCharacterRenderService::class)->effectiveState($profile);
    $renderStatus = $renderState['status'] ?? 'idle';
    $renderReadyAt = $renderState['ready_at'] ?? null;
    $renderMode = old('character_render_mode', $renderState['mode'] ?? 'success');
    $renderResultUrl = $renderState['result_url'] ?? null;
    $renderError = $renderState['error'] ?? null;

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
        'male_fade' => ['label' => 'Фейд', 'gender' => 'male'],
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
        'short_beard' => 'Короткая борода',
        'full_beard' => 'Полная борода',
    ];
    $piercings = [
        'left_ear' => 'Левое ухо',
        'right_ear' => 'Правое ухо',
        'both_ears' => 'Оба уха',
        'eyebrow' => 'Бровь',
        'nose' => 'Нос',
        'lip' => 'Губа',
    ];
    $tattooLocations = [
        'left_upper_arm' => 'Левое плечо',
        'right_upper_arm' => 'Правое плечо',
        'left_forearm' => 'Левое предплечье',
        'right_forearm' => 'Правое предплечье',
        'neck' => 'Шея',
        'chest' => 'Грудь',
        'back' => 'Спина',
        'left_calf' => 'Левая голень',
        'right_calf' => 'Правая голень',
    ];
@endphp

<section class="account-player-profile__section account-player-character-section">
    <div>
        <h3 class="h4 mb-1">Характеристики игрока</h3>
        <p class="text-muted mb-0">Основные физические данные, игровой образ и баскетбольная специализация.</p>
    </div>

    <div class="account-player-character-layout account-player-character-layout--image">
        <div class="account-player-character-visual">
            <div
                class="account-player-character-stage account-player-character-stage--image"
                data-player-character-stage
                data-gender="{{ $characterGender }}"
                data-render-status="{{ $renderStatus }}"
                data-render-ready-at="{{ $renderReadyAt ?? '' }}"
                data-render-mode="{{ $renderMode }}"
                data-render-result-url="{{ $renderResultUrl ?? '' }}"
                data-render-error="{{ $renderError ?? '' }}"
                data-has-height="{{ $characterHeightCm !== null ? 'true' : 'false' }}"
                role="group"
                aria-label="Игровой образ игрока на шкале роста"
            >
                <div class="account-player-character-stage__width" aria-hidden="true">
                    <span></span>
                    <strong>200 см</strong>
                    <span></span>
                </div>

                <div class="account-player-character-stage__plot" data-player-character-plot>
                    <div class="account-player-character-stage__scale" aria-hidden="true">
                        @foreach([250, 200, 150, 100, 50, 0] as $scaleHeight)
                            <span style="--player-scale-y: {{ (250 - $scaleHeight) / 2.5 }}%;">{{ $scaleHeight }}</span>
                        @endforeach
                    </div>
                    <div class="account-player-character-stage__axis" aria-hidden="true"></div>

                    <button
                        type="button"
                        class="account-player-character-silhouette account-player-character-silhouette--{{ $characterGender }}"
                        data-player-character-silhouette
                        data-player-character-open-modal
                        aria-label="Настроить игровой образ"
                    >
                        <span class="account-player-character-silhouette__head"></span>
                        <span class="account-player-character-silhouette__neck"></span>
                        <span class="account-player-character-silhouette__torso"></span>
                        <span class="account-player-character-silhouette__arm account-player-character-silhouette__arm--left"></span>
                        <span class="account-player-character-silhouette__arm account-player-character-silhouette__arm--right"></span>
                        <span class="account-player-character-silhouette__leg account-player-character-silhouette__leg--left"></span>
                        <span class="account-player-character-silhouette__leg account-player-character-silhouette__leg--right"></span>
                        <span class="account-player-character-silhouette__hint">Настроить образ</span>
                    </button>

                    <img
                        class="account-player-character-generated"
                        data-player-character-generated
                        src="{{ $renderResultUrl ?? '' }}"
                        alt="Сгенерированный игровой образ"
                    >

                    <div class="account-player-character-loading" data-player-character-loading aria-live="polite">
                        <img src="/images/player-character/preloader.gif" alt="" aria-hidden="true">
                        <strong>Собираем игровой образ…</strong>
                        <span>Обычно это занимает несколько секунд.</span>
                    </div>

                    <div class="account-player-character-render-error" data-player-character-render-error aria-live="polite">
                        <strong>Не удалось собрать игровой образ</strong>
                        <span data-player-character-render-error-message>{{ $renderError }}</span>
                        <button type="button" class="btn btn--secondary btn--xs" data-player-character-open-modal>Изменить настройки</button>
                    </div>

                    <button
                        type="button"
                        class="account-player-character-stage__height-marker account-player-character-stage__height-marker--dot"
                        data-player-character-height-marker
                        aria-expanded="false"
                        aria-label="{{ $characterHeightCm !== null ? $characterHeightCm.' см' : 'Рост не указан' }}"
                        hidden
                    >
                        <span data-player-character-height-label>{{ $characterHeightCm !== null ? $characterHeightCm.' см' : 'Рост не указан' }}</span>
                    </button>

                    <div class="account-player-character-stage__floor" aria-hidden="true"></div>
                </div>
            </div>
        </div>

        <div class="account-player-character-controls">
            <div class="account-player-profile__grid">
                <div class="form-group field account-player-profile__field">
                    <label for="player-height">Рост, см</label>
                    <select id="player-height" class="form-select" name="height_cm" data-player-character-input="height">
                        <option value="">Не указан</option>
                        @for($height = 150; $height <= 220; $height++)
                            <option value="{{ $height }}" @selected((string) $currentHeight === (string) $height)>{{ $height }} см</option>
                        @endfor
                    </select>
                    @error('height_cm') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>

                <div class="form-group field account-player-profile__field">
                    <label for="player-weight">Вес, кг</label>
                    <select id="player-weight" class="form-select" name="weight_kg" data-player-character-input="weight">
                        <option value="">Не указан</option>
                        @for($weight = 40; $weight <= 140; $weight++)
                            <option value="{{ $weight }}" @selected($currentWeight !== null && (int) $currentWeight === $weight)>{{ $weight }} кг</option>
                        @endfor
                    </select>
                    @error('weight_kg') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>

                <div class="form-group field account-player-profile__field">
                    <label for="player-body-type">Сложение</label>
                    <select id="player-body-type" class="form-select" name="body_type" data-player-character-input="body-type">
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

            <div class="account-player-character-image-card">
                <div>
                    <span class="eyebrow">Игровой образ</span>
                    <h4>{{ $renderStatus === 'ready' ? 'Образ готов' : 'Настройте внешний вид игрока' }}</h4>
                    <p>Пол берётся из профиля: {{ $characterGender === 'female' ? 'женский' : 'мужской' }}. Нажмите на силуэт или кнопку ниже, чтобы изменить внешность.</p>
                </div>
                <button type="button" class="btn btn--secondary btn--xs" data-player-character-open-modal>
                    Настроить образ
                </button>
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

    <input type="hidden" name="character[skin_tone]" value="{{ $characterSkinTone }}" data-player-character-field="skin-tone">
    <input type="hidden" name="character[hairstyle]" value="{{ $characterHairstyle }}" data-player-character-field="hairstyle">
    <input type="hidden" name="character[hair_color]" value="{{ $characterHairColor }}" data-player-character-field="hair-color">
    <input type="hidden" name="character[facial_hair]" value="{{ $characterGender === 'female' ? 'none' : $characterFacialHair }}" data-player-character-field="facial-hair">
    <input type="hidden" name="character[uniform_kit]" value="{{ $characterUniformKit }}">
    <input type="hidden" name="character_face_photo_data" value="" data-player-character-face-data>
    <input type="hidden" name="character_render_requested" value="0" data-player-character-render-requested>

    <dialog class="account-player-character-modal" data-player-character-modal aria-labelledby="player-character-modal-title">
        <div class="account-player-character-modal__header">
            <div>
                <span class="eyebrow">Игровой образ</span>
                <h3 id="player-character-modal-title">Настройте персонажа</h3>
            </div>
            <button type="button" class="account-player-character-modal__close" data-player-character-close-modal aria-label="Закрыть">×</button>
        </div>

        <div class="account-player-character-modal__body">
            <section class="account-player-character-modal__group">
                <h4>Тон кожи</h4>
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
            </section>

            <section class="account-player-character-modal__group">
                <h4>Причёска</h4>
                <div class="account-player-character-configurator__chips">
                    @foreach($hairstyles as $value => $hairstyle)
                        @continue($hairstyle['gender'] !== $characterGender)
                        <button
                            type="button"
                            class="account-player-character-configurator__chip"
                            data-player-character-choice="hairstyle"
                            data-value="{{ $value }}"
                            aria-pressed="{{ $characterHairstyle === $value ? 'true' : 'false' }}"
                        >{{ $hairstyle['label'] }}</button>
                    @endforeach
                </div>
            </section>

            <section class="account-player-character-modal__group">
                <h4>Цвет волос</h4>
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
            </section>

            @if($characterGender === 'male')
                <section class="account-player-character-modal__group">
                    <h4>Волосы на лице</h4>
                    <div class="account-player-character-configurator__chips">
                        @foreach($facialHairStyles as $value => $label)
                            <button
                                type="button"
                                class="account-player-character-configurator__chip"
                                data-player-character-choice="facial-hair"
                                data-value="{{ $value }}"
                                aria-pressed="{{ $characterFacialHair === $value ? 'true' : 'false' }}"
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="account-player-character-modal__group">
                <h4>Пирсинг</h4>
                <div class="account-player-character-modal__check-grid">
                    @foreach($piercings as $value => $label)
                        <label>
                            <input type="checkbox" name="character[piercings][]" value="{{ $value }}" @checked(in_array($value, $characterPiercings, true))>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </section>

            <section class="account-player-character-modal__group">
                <h4>Татуировки</h4>
                <div class="account-player-character-modal__check-grid">
                    @foreach($tattooLocations as $value => $label)
                        <label>
                            <input type="checkbox" name="character[tattoos][]" value="{{ $value }}" @checked(in_array($value, $characterTattoos, true))>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <label class="account-player-character-modal__textarea">
                    <span>Описание татуировок</span>
                    <textarea name="character[tattoo_note]" rows="3" maxlength="500" placeholder="Например: чёрно-белый рукав на правом предплечье">{{ $characterTattooNote }}</textarea>
                </label>
            </section>

            <section class="account-player-character-modal__group">
                <h4>Фото лица</h4>
                <p>Необязательно. Фото сохраняется приватно и позже будет передаваться генератору только с backend.</p>
                <label class="account-player-character-face-upload">
                    <input type="file" accept="image/jpeg,image/png,image/webp" data-player-character-face-input>
                    <span data-player-character-face-label>{{ $hasFaceReference ? 'Фото уже загружено — можно заменить' : 'Выбрать фото' }}</span>
                </label>
                @error('character_face_photo') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </section>

            <section class="account-player-character-modal__group account-player-character-modal__group--test">
                <h4>Тестовый ответ</h4>
                <p>Временно, до подключения OpenAI API. Позволяет проверить успешный результат и состояние ошибки.</p>
                <select class="form-select" name="character_render_mode">
                    <option value="success" @selected($renderMode !== 'error')>Успешная генерация</option>
                    <option value="error" @selected($renderMode === 'error')>Вернуть ошибку</option>
                </select>
            </section>
        </div>

        <div class="account-player-character-modal__footer">
            <button type="button" class="btn btn--secondary btn--xs" data-player-character-close-modal>Отмена</button>
            <button type="button" class="btn btn--primary btn--xs" data-player-character-apply>Применить настройки</button>
        </div>
    </dialog>
</section>
