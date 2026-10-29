@php
    use App\Modules\Identity\Domain\Support\PlayerCharacterAppearanceOptions as Appearance;

    $profile = $user->playerProfile;
    $savedProfileGender = $personalProfile?->gender?->value;
    // The character renderer may use a male placeholder; never expose it as saved profile data.
    $gender = Appearance::normalizeGender($savedProfileGender);
    $genderDisplay = match ($savedProfileGender) {
        'male' => 'мужской',
        'female' => 'женский',
        default => 'не установлен',
    };
    $storedAppearance = is_array(data_get($profile?->extra, 'character')) ? data_get($profile->extra, 'character') : [];
    $character = array_merge(Appearance::defaults($gender), $storedAppearance);
    $tab = old('section', request()->query('tab', 'physical')) === 'game' ? 'game' : 'physical';
    $savedHeight = old('height_cm', $profile?->height_cm);
    $savedWeight = old('weight_kg', $profile?->weight_kg);
    $heightValue = $savedHeight === null || $savedHeight === '' ? 185 : max(150, min(220, (int) $savedHeight));
    $weightValue = $savedWeight === null || $savedWeight === '' ? 80 : max(40, min(140, (int) $savedWeight));
    $selectedPositions = old('positions', $profile?->positions?->map(fn($entry) => $entry->position->value)->all() ?? []);
    $playerSelf = $profile?->selfAssessment;
    $objective = $user->playerObjectiveAssessment;
    $chestOptions = ['small' => 'Небольшой', 'medium' => 'Средний', 'large' => 'Большой', 'full' => 'Выраженный'];
    $uniformOptions = ['mskba_home' => 'MSKBA домашняя', 'mskba_light' => 'MSKBA светлая', 'street_black' => 'Street black', 'city_night' => 'City night'];
    $shoesOptions = Appearance::shoeLabels();
    $equipmentOptions = [
        'elbow_left' => 'Налокотник слева', 'elbow_right' => 'Налокотник справа',
        'wristband_left' => 'Напульсник слева', 'wristband_right' => 'Напульсник справа',
        'knee_left' => 'Наколенник слева', 'knee_right' => 'Наколенник справа',
        'headband' => 'Повязка на голову',
    ];
@endphp

<div class="app-player-workspace" data-player-workspace>
    @if(session('status'))
        <p class="app-player-workspace__notice" role="status">{{ session('status') }}</p>
    @endif
    @if($errors->any())
        <div class="app-player-workspace__error" role="alert">
            <strong>Проверь данные перед сохранением.</strong>
            <ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
        </div>
    @endif

    <nav class="app-player-workspace__tabs" aria-label="Параметры роли Игрок" data-player-section-nav>
        <a href="{{ route('account.participation-role', ['role' => 'player', 'tab' => 'physical']) }}"
            class="{{ $tab === 'physical' ? 'is-active' : '' }}"
            @if($tab === 'physical') aria-current="page" @endif>Физические данные</a>
        <a href="{{ route('account.participation-role', ['role' => 'player', 'tab' => 'game']) }}"
            class="{{ $tab === 'game' ? 'is-active' : '' }}"
            @if($tab === 'game') aria-current="page" @endif>Игровые характеристики</a>
    </nav>

    @if($tab === 'physical')
        <div class="app-player-workspace__physical">
            <aside class="app-player-workspace__preview" aria-label="Предпросмотр персонажа" data-player-preview
                data-gender="{{ $gender }}"
                data-default-src="{{ asset('images/blank/avatar/body-'.$gender.'.png') }}"
                data-history-url="{{ route('account.player-profile.update') }}">
                <div class="app-player-workspace__stage" data-player-stage
                    role="img" aria-label="Изображение персонажа рядом со шкалой роста">
                    <div class="app-player-workspace__scale" aria-hidden="true">
                        @foreach([250,200,150,100,50,0] as $height)
                            <span style="bottom:{{ $height / 2.5 }}%">{{ $height }} см</span>
                        @endforeach
                    </div>
                    <div class="app-player-workspace__figure">
                        <img src="{{ asset('images/blank/avatar/body-'.$gender.'.png') }}"
                            alt="Внешний вид баскетболиста" data-player-figure>
                    </div>
                    <div class="app-player-workspace__floor" aria-hidden="true"></div>
                </div>
                <div class="app-player-workspace__metrics" aria-live="polite">
                    <span data-player-height-label>Рост: {{ $profile?->height_cm ? $profile->height_cm.' см' : '185 см (пример)' }}</span>
                    <span data-player-weight-label>Вес: {{ $profile?->weight_kg ? $profile->weight_kg.' кг' : 'не указан' }}</span>
                </div>
                <p class="app-player-workspace__gender-meta">
                    <span>Пол:</span>
                    <button type="button" class="app-player-workspace__gender-link"
                        data-player-gender-open data-player-gender-value
                        aria-haspopup="dialog" aria-controls="app-player-gender-explanation">{{ $genderDisplay }}</button>
                </p>
            </aside>

            <section class="app-player-workspace__editor" aria-labelledby="app-player-physical-title">
                <div class="app-player-workspace__section-heading">
                    <h2 id="app-player-physical-title">Физические данные</h2>
                    <p>Рост, вес, телосложение и настройки игрового образа.</p>
                </div>
                <form method="POST" action="{{ route('account.player-profile.update') }}" data-player-physical-form class="app-player-workspace__form">
                    @csrf @method('PATCH')
                    <input type="hidden" name="section" value="physical">
                    <div class="app-player-workspace__fields">
                        <label class="app-player-workspace__field app-player-workspace__range-field" for="player-height">
                            <span class="app-player-workspace__range-heading">Рост, см <output for="player-height" data-physical-height-value>{{ $heightValue }} см</output></span>
                            <input id="player-height" type="range" name="height_cm" min="150" max="220" step="1" value="{{ $heightValue }}" data-physical-height>
                            <span class="app-player-workspace__range-bounds" aria-hidden="true"><span>150 см</span><span>220 см</span></span>
                        </label>
                        <label class="app-player-workspace__field app-player-workspace__range-field" for="player-weight">
                            <span class="app-player-workspace__range-heading">Вес, кг <output for="player-weight" data-physical-weight-value>{{ $weightValue }} кг</output></span>
                            <input id="player-weight" type="range" name="weight_kg" min="40" max="140" step="1" value="{{ $weightValue }}" data-physical-weight>
                            <span class="app-player-workspace__range-bounds" aria-hidden="true"><span>40 кг</span><span>140 кг</span></span>
                        </label>
                        <label class="app-player-workspace__field">Телосложение
                            <span class="select-control">
                                <select name="body_type" data-physical-body-type>
                                @foreach($playerBodyTypes as $bodyType)
                                    <option value="{{ $bodyType->value }}" @selected(old('body_type',$profile?->body_type?->value ?? 'normal')===$bodyType->value)>{{ $bodyType->label() }}</option>
                                @endforeach
                            </select>
                                @include('account.partials.native-select-chevron')
                            </span>
                        </label>
                        @if($gender === 'female')
                            <label class="app-player-workspace__field">Объём груди
                                <span class="select-control">
                                    <select name="character[chest_volume]">
                                    @foreach($chestOptions as $value=>$label)
                                        <option value="{{ $value }}" @selected(old('character.chest_volume',$character['chest_volume'] ?? 'medium')===$value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                    @include('account.partials.native-select-chevron')
                                </span>
                            </label>
                        @endif
                    </div>

                    <fieldset class="app-player-workspace__equipment">
                        <legend>Одежда и кроссовки</legend>
                        <div class="app-player-workspace__fields">
                            <label class="app-player-workspace__field">Комплект формы
                                <span class="select-control">
                                    <select name="character[uniform_kit]">
                                    @foreach($uniformOptions as $value=>$label)
                                        <option value="{{ $value }}" @selected(old('character.uniform_kit',$character['uniform_kit'])===$value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                    @include('account.partials.native-select-chevron')
                                </span>
                            </label>
                            <label class="app-player-workspace__field">Кроссовки
                                <span class="select-control">
                                    <select name="character[shoes]">
                                    @foreach($shoesOptions as $value=>$label)
                                        <option value="{{ $value }}" @selected(old('character.shoes',$character['shoes'] ?? 'white')===$value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                    @include('account.partials.native-select-chevron')
                                </span>
                            </label>
                        </div>
                    </fieldset>
                    <fieldset class="app-player-workspace__equipment">
                        <legend>Спортивные аксессуары</legend>
                        <div class="app-player-workspace__checks">
                            @foreach($equipmentOptions as $value=>$label)
                                <label><input type="checkbox" name="character[attributes][]" value="{{ $value }}"
                                    @checked(in_array($value,(array)old('character.attributes',$character['attributes'] ?? []),true))> {{ $label }}</label>
                            @endforeach
                        </div>
                    </fieldset>
                    <div class="app-player-workspace__actions">
                        <button class="app-player-workspace__primary" type="submit">Применить</button>
                    </div>
                </form>
                <section class="app-player-workspace__generation" aria-labelledby="app-player-generation-title">
                    <h3 id="app-player-generation-title">Реалистичный 2D-аватар</h3>
                    <p>Для генерации нужны проверенные фотографии лица: анфас и один боковой ракурс. Генерация платная — стоимость и подтверждение появятся до списания средств.</p>
                    <div class="app-player-workspace__fields">
                        <label class="app-player-workspace__field">Анфас
                            <input type="file" accept="image/jpeg,image/png,image/webp" data-player-face-slot="front">
                        </label>
                        <label class="app-player-workspace__field">Левый профиль
                            <input type="file" accept="image/jpeg,image/png,image/webp" data-player-face-slot="left">
                        </label>
                        <label class="app-player-workspace__field">Правый профиль
                            <input type="file" accept="image/jpeg,image/png,image/webp" data-player-face-slot="right">
                        </label>
                        <label class="app-player-workspace__field">Цвета команды (необязательно)
                            <span class="select-control">
                                <select data-player-generation-team>
                                <option value="">Без команды</option>
                                @foreach($playerTeams as $team)
                                    <option value="{{ $team->id }}">{{ $team->name }}</option>
                                @endforeach
                            </select>
                                @include('account.partials.native-select-chevron')
                            </span>
                        </label>
                    </div>
                    <label class="app-player-workspace__checkbox"><input type="checkbox" data-player-generation-logo> Использовать логотип выбранной команды (при наличии)</label>
                    <div class="app-player-workspace__actions">
                        <button type="button" class="app-player-workspace__secondary" data-player-generate>Проверить стоимость и сгенерировать</button>
                    </div>
                    <p class="app-player-workspace__generation-status" data-player-generation-status role="status" aria-live="polite"></p>
                </section>
            </section>
        </div>
    @else
        <section class="app-player-workspace__game" aria-labelledby="app-player-game-title">
            <div class="app-player-workspace__section-heading">
                <h2 id="app-player-game-title">Игровые характеристики</h2>
                <p>Амплуа, опыт, самооценка и показатели из подтверждённых матчей.</p>
            </div>
            <form method="POST" action="{{ route('account.player-profile.update') }}" class="app-player-workspace__form" data-player-game-form>
                @csrf @method('PATCH')
                <input type="hidden" name="section" value="game">
                <div class="app-player-workspace__fields">
                    <label class="app-player-workspace__field">Играю с
                        <span class="select-control">
                            <select name="experience_started_year">
                            <option value="">Не указано</option>
                            @for($year=now()->year-10;$year>=now()->year-50;$year--)
                                <option value="{{ $year }}" @selected((string)old('experience_started_year',$profile?->experience_started_year)===(string)$year)>{{ $year }}</option>
                            @endfor
                        </select>
                            @include('account.partials.native-select-chevron')
                        </span>
                    </label>
                </div>
                <fieldset class="app-player-workspace__equipment">
                    <legend>Амплуа</legend>
                    <p>Можно выбрать несколько игровых позиций.</p>
                    <div class="app-player-workspace__checks">
                        @foreach($playerPositions as $position)
                            <label><input type="checkbox" name="positions[]" value="{{ $position->value }}" @checked(in_array($position->value,(array)$selectedPositions,true))> {{ $position->label() }}</label>
                        @endforeach
                    </div>
                </fieldset>
                <h3>Самооценка игровых навыков</h3>
                <p class="app-player-workspace__hint">Значения от 1 до 10 — личная оценка, она не влияет на объективную статистику матчей.</p>
                <div class="app-player-workspace__skills">
                    @foreach($playerSkills as $skill=>$label)
                        @php($skillValue = old('self_assessment.'.$skill, $playerSelf?->{$skill} ?? 5))
                        <label class="app-player-workspace__skill" for="app-player-skill-{{ $skill }}">
                            <span>{{ $label }}</span>
                            <input id="app-player-skill-{{ $skill }}" type="range" name="self_assessment[{{ $skill }}]" min="1" max="10" step="1" value="{{ $skillValue }}" data-player-skill>
                            <output for="app-player-skill-{{ $skill }}">{{ $skillValue }}</output>
                        </label>
                    @endforeach
                </div>
                <label class="app-player-workspace__field app-player-workspace__field--wide">О себе как об игроке
                    <textarea name="comment" rows="4" maxlength="1000">{{ old('comment',$profile?->comment) }}</textarea>
                </label>
                <div class="app-player-workspace__actions">
                    <button type="submit" class="app-player-workspace__primary">Сохранить игровые данные</button>
                </div>
            </form>
            <section class="app-player-workspace__objective" aria-labelledby="app-player-objective-title">
                <div class="app-player-workspace__section-heading">
                    <h2 id="app-player-objective-title">Объективные игровые показатели</h2>
                    <p>Формируются автоматически из подтверждённой статистики матчей.</p>
                </div>
                @if($objective)
                    <p class="app-player-workspace__objective-meta">Матчей учтено: {{ $objective->games_count }} · Достоверность {{ (int)round((float)$objective->confidence*100) }}%</p>
                @endif
                <div class="app-player-workspace__objective-list">
                    @forelse(collect($objectivePlayerSkills)->filter(fn($label,$skill)=>$objective?->{$skill} !== null) as $skill=>$label)
                        @php($score = min(10,max(0,(float)$objective->{$skill})))
                        <div data-objective-skill="{{ $skill }}" class="app-player-workspace__objective-item">
                            <div><span>{{ $label }}</span><strong>{{ number_format($score,1,',',' ') }} / 10</strong></div>
                            <progress value="{{ $score }}" max="10">{{ $score }}</progress>
                        </div>
                    @empty
                        <p class="app-player-workspace__empty">Показателей пока нет. Они появятся после подтверждения результатов первой сыгранной игры.</p>
                    @endforelse
                </div>
            </section>
        </section>
    @endif

    @if($tab === 'physical')
        <dialog class="mskba-modal app-player-workspace__gender-dialog"
            id="app-player-gender-explanation" data-player-gender-dialog
            aria-labelledby="app-player-gender-explanation-title">
            <div class="mskba-modal__header app-profile__request-header">
                <h2 id="app-player-gender-explanation-title" tabindex="-1">Пол игрока</h2>
                <button type="button" class="app-profile__dialog-close"
                    data-player-gender-close aria-label="Закрыть">×</button>
            </div>
            <div class="mskba-modal__body mskba-scroll app-profile__request-body">
                <p>Пол игрового персонажа берётся из раздела «Личные данные» твоего профиля.
                    Если эти сведения уже подтверждены, их можно изменить только через заявку
                    на проверку. Остальные доступные поля редактируются как обычно.</p>
            </div>
            <div class="mskba-modal__footer app-profile__request-footer">
                <button type="button" class="button secondary" data-player-gender-close>Отмена</button>
                <button type="button" class="button primary" data-player-personal-open>Личные данные</button>
            </div>
        </dialog>

        <dialog class="mskba-modal app-player-workspace__personal-dialog"
            id="app-player-personal-dialog" data-player-personal-dialog
            aria-labelledby="app-player-personal-dialog-title">
            <div class="mskba-modal__header app-profile__request-header">
                <h2 id="app-player-personal-dialog-title" tabindex="-1">Личные данные</h2>
                <button type="button" class="app-profile__dialog-close"
                    data-player-personal-close aria-label="Закрыть">×</button>
            </div>
            <div class="mskba-modal__body mskba-scroll app-player-workspace__personal-body">
                <div class="app-profile" data-account-profile>
                    @include('theme::pages.account.partials.profile-personal-editor', ['profile' => $personalProfile, 'profilePersonalInDialog' => true])
                </div>
            </div>
            <div class="mskba-modal__footer app-profile__request-footer app-player-workspace__personal-footer">
                @include('theme::pages.account.partials.profile-personal-actions', ['profilePersonalInDialog' => true])
            </div>
        </dialog>
        @include('theme::pages.account.partials.profile-change-request-dialog', ['profile' => $personalProfile])
    @endif
</div>
