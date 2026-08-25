@php
    $currentHeight = old('height_cm', $profile?->height_cm);
    $currentWeight = old('weight_kg', $profile?->weight_kg);
    $currentBodyType = old('body_type', $profile?->body_type?->value);
    $characterHeightCm = $currentHeight !== null && $currentHeight !== '' ? (int) $currentHeight : null;
    $characterWeightKg = $currentWeight !== null && $currentWeight !== '' ? (int) $currentWeight : null;
    $characterPreviewHeightCm = $characterHeightCm ?? 180;
    $characterHeightPercent = round(min(250, max(0, $characterPreviewHeightCm)) / 250 * 100, 2);
    $characterGender = $user->profile?->gender?->value ?? 'unspecified';
@endphp

<section class="account-player-profile__section account-player-character-section">
    <div>
        <h3 class="h4 mb-1">Характеристики игрока</h3>
        <p class="text-muted mb-0">Основные физические данные и баскетбольная специализация.</p>
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
                data-skin-tone="default"
                data-hairstyle="default"
                data-has-height="{{ $characterHeightCm !== null ? 'true' : 'false' }}"
                data-renderer="placeholder"
                role="img"
                aria-label="Персонаж игрока на шкале роста"
                style="--player-height-percent: {{ $characterHeightPercent }};"
            >
                <div class="account-player-character-stage__width" aria-hidden="true">
                    <span></span>
                    <strong>200 см</strong>
                    <span></span>
                </div>

                <div class="account-player-character-stage__scale" aria-hidden="true">
                    @foreach([250, 200, 150, 100, 50, 0] as $scaleHeight)
                        <span style="--player-scale-y: {{ (250 - $scaleHeight) / 2.5 }}%;">
                            {{ $scaleHeight }}
                        </span>
                    @endforeach
                </div>

                <div class="account-player-character-stage__axis" aria-hidden="true"></div>

                <div class="account-player-character-stage__height-marker" aria-hidden="true">
                    <span data-player-character-height-label>
                        {{ $characterHeightCm !== null ? $characterHeightCm.' см' : 'Рост не указан' }}
                    </span>
                </div>

                <div class="account-player-character-stage__figure" aria-hidden="true">
                    <span class="account-player-character-stage__head"></span>
                    <span class="account-player-character-stage__torso"></span>
                    <span class="account-player-character-stage__arm account-player-character-stage__arm--left"></span>
                    <span class="account-player-character-stage__arm account-player-character-stage__arm--right"></span>
                    <span class="account-player-character-stage__leg account-player-character-stage__leg--left"></span>
                    <span class="account-player-character-stage__leg account-player-character-stage__leg--right"></span>
                </div>

                <div class="account-player-character-stage__floor" aria-hidden="true"></div>
                <span class="account-player-character-stage__badge">PLAYER CHARACTER</span>
            </div>

            <p class="account-player-character-visual__caption">
                Масштаб сцены: 200 × 250 см. Внешность персонажа будет настраиваться отдельно.
            </p>
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
                            <option
                                value="{{ $height }}"
                                @selected((string) $currentHeight === (string) $height)
                            >{{ $height }} см</option>
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
                            <option
                                value="{{ $weight }}"
                                @selected($currentWeight !== null && (int) $currentWeight === $weight)
                            >{{ $weight }} кг</option>
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
                            <option
                                value="{{ $bodyType->value }}"
                                @selected($currentBodyType === $bodyType->value)
                            >{{ $bodyType->label() }}</option>
                        @endforeach
                    </select>
                    @error('body_type') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>

                <div class="form-group field account-player-profile__field">
                    <label for="player-experience-year">Играю с</label>
                    <select
                        id="player-experience-year"
                        class="form-select"
                        name="experience_started_year"
                    >
                        <option value="">Не указано</option>
                        @for($year = now()->year - 10; $year >= now()->year - 50; $year--)
                            <option
                                value="{{ $year }}"
                                @selected((string) old('experience_started_year', $profile?->experience_started_year) === (string) $year)
                            >{{ $year }}</option>
                        @endfor
                    </select>
                    @error('experience_started_year') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
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
