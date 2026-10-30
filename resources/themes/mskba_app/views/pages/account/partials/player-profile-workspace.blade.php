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
    $equipmentGroups = [
        'elbow' => ['name' => 'Налокотник', 'left' => 'elbow_left', 'right' => 'elbow_right'],
        'wristband' => ['name' => 'Напульсник', 'left' => 'wristband_left', 'right' => 'wristband_right'],
        'knee' => ['name' => 'Наколенник', 'left' => 'knee_left', 'right' => 'knee_right'],
    ];
    $selectedEquipment = Appearance::normalizeAttributes((array) old('character.attributes', $character['attributes'] ?? []));
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
                data-default-src="{{ asset('images/blank/avatar/body-'.$gender.'.webp') }}"
                data-history-url="{{ route('account.player-profile.update') }}">
                <div class="app-player-workspace__stage" data-player-stage
                    role="img" aria-label="Изображение персонажа рядом со шкалой роста">
                    <div class="app-player-workspace__scale" aria-hidden="true">
                        @foreach([0,50,100,150,200,225] as $height)
                            <div class="app-player-workspace__scale-tick" style="bottom:{{ round($height / 225 * 100, 4) }}%">
                                <span>{{ $height }} см</span>
                            </div>
                        @endforeach
                        <div class="app-player-workspace__height-marker" data-player-height-marker
                            style="bottom:{{ round($heightValue / 225 * 100, 4) }}%">
                            <span data-player-height-marker-label>{{ $heightValue }} см</span>
                        </div>
                    </div>
                    <div class="app-player-workspace__figure">
                        <img src="{{ asset('images/blank/avatar/body-'.$gender.'.webp') }}"
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
                <section class="app-player-workspace__avatar-library" aria-label="Мои сгенерированные аватары" data-player-avatar-library>
                    <h3 class="app-player-workspace__avatar-heading" data-player-avatar-heading hidden>Аватаров: <span data-player-avatar-count>0</span></h3>
                    <div class="app-player-workspace__avatar-rail mskba-scroll"
                        role="group" aria-label="Выбор аватара для предпросмотра" data-player-avatar-rail hidden></div>
                    <p class="app-player-workspace__avatar-empty" data-player-avatar-empty>Сгенерированных аватаров пока нет.</p>
                    <div class="app-player-workspace__avatar-actions" data-player-avatar-actions hidden>
                        <button type="button" class="app-player-workspace__secondary" data-player-avatar-make-primary hidden>Сделать основным</button>
                        <span class="app-player-workspace__avatar-primary-label" data-player-avatar-primary-label hidden>Основной аватар</span>
                    </div>
                    <p class="app-player-workspace__avatar-feedback" role="status" aria-live="polite" data-player-avatar-feedback></p>
                </section>
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
                        <div class="app-player-workspace__checks app-player-workspace__equipment-groups">
                            @foreach($equipmentGroups as $type => $option)
                                @php
                                    $hasLeft = in_array($option['left'], $selectedEquipment, true);
                                    $hasRight = in_array($option['right'], $selectedEquipment, true);
                                    $equipmentSuffix = $hasLeft && $hasRight ? ' (л/п)' : ($hasLeft ? ' (л)' : ($hasRight ? ' (п)' : ''));
                                @endphp
                                <div class="app-player-workspace__equipment-group" data-player-equipment-group
                                    data-equipment-type="{{ $type }}" data-equipment-name="{{ $option['name'] }}"
                                    data-equipment-left="{{ $option['left'] }}" data-equipment-right="{{ $option['right'] }}">
                                    <label class="check-label app-player-workspace__equipment-toggle">
                                        <input type="checkbox" data-player-equipment-toggle @checked($hasLeft || $hasRight)>
                                        <span>{{ $option['name'] }}<span data-player-equipment-suffix>{{ $equipmentSuffix }}</span></span>
                                    </label>
                                    <button class="app-player-workspace__equipment-edit" type="button"
                                        data-player-equipment-edit @if(!($hasLeft || $hasRight)) hidden @endif
                                        aria-label="Выбрать стороны: {{ $option['name'] }}">Изменить</button>
                                    <span data-player-equipment-values>
                                        @if($hasLeft)<input type="hidden" name="character[attributes][]" value="{{ $option['left'] }}">@endif
                                        @if($hasRight)<input type="hidden" name="character[attributes][]" value="{{ $option['right'] }}">@endif
                                    </span>
                                </div>
                            @endforeach
                            <label class="check-label app-player-workspace__equipment-toggle app-player-workspace__equipment-headband">
                                <input type="checkbox" name="character[attributes][]" value="headband"
                                    @checked(in_array('headband', $selectedEquipment, true))>
                                <span>Повязка на голову</span>
                            </label>
                        </div>
                    </fieldset>
                    <div class="app-player-workspace__actions">
                        <button class="app-player-workspace__primary" type="submit">Применить</button>
                    </div>
                </form>
                @php
                    $avatarOffer = $generationOffer ?? [
                        'price_minor' => null, 'currency' => 'RUB', 'bonus_balance_minor' => 0,
                        'real_balance_minor' => 0, 'available_minor' => 0,
                        'available_generations' => null, 'can_generate' => false, 'bonus_opportunities' => [],
                    ];
                    $avatarPrice = $avatarOffer['price_minor'];
                    $avatarCanGenerate = (bool) $avatarOffer['can_generate'];
                    $formatRub = static fn (int $minor): string => number_format($minor / 100, 2, ',', ' ').' ₽';
                    // The reward catalogue stores monetary minor units; the UI presents
                    // the same numeric bonus credit as points (10000 => 100 баллов).
                    $formatPoints = static function (int $minor): string {
                        $quantity = rtrim(rtrim(number_format($minor / 100, 2, ',', ' '), '0'), ',');
                        if ($minor % 100 !== 0) {
                            return $quantity.' балла';
                        }
                        $points = intdiv($minor, 100);
                        $mod100 = $points % 100;
                        $mod10 = $points % 10;
                        $suffix = $mod100 >= 11 && $mod100 <= 14 ? 'баллов'
                            : ($mod10 === 1 ? 'балл' : ($mod10 >= 2 && $mod10 <= 4 ? 'балла' : 'баллов'));
                        return $quantity.' '.$suffix;
                    };
                @endphp
                <section class="app-player-workspace__generation" aria-labelledby="app-player-generation-title"
                    data-player-generation-offer data-player-generation-affordable="{{ $avatarCanGenerate ? 'true' : 'false' }}">
                    <div class="app-player-workspace__section-heading">
                        <h3 id="app-player-generation-title">Генерация аватара</h3>
                        <p>Реалистичное изображение игрока по проверенным фотографиям анфас и слева или справа.</p>
                    </div>
                    <div class="app-player-workspace__generation-summary" aria-label="Стоимость и баланс генерации">
                        <div class="app-player-workspace__generation-stat">
                            <span>Стоимость генерации</span>
                            <strong data-player-offer-price>{{ $avatarPrice === null ? 'Недоступна' : $formatRub($avatarPrice) }}</strong>
                        </div>
                        <div class="app-player-workspace__generation-stat">
                            <span>Бонусный баланс</span>
                            <strong data-player-offer-bonus>{{ $formatRub((int) $avatarOffer['bonus_balance_minor']) }}</strong>
                        </div>
                        <div class="app-player-workspace__generation-stat">
                            <span>Доступно для оплаты (всего)</span>
                            <strong data-player-offer-total>{{ $formatRub((int) $avatarOffer['available_minor']) }}</strong>
                        </div>
                        <div class="app-player-workspace__generation-stat">
                            <span>Доступно генераций</span>
                            <strong data-player-offer-count>{{ $avatarPrice === null ? '—' : ($avatarPrice === 0 ? 'Без оплаты' : (string) $avatarOffer['available_generations']) }}</strong>
                        </div>
                    </div>
                    <p class="app-player-workspace__generation-hint">При оплате используются сначала бонусные средства, затем реальные. Стоимость и баланс проверяются ещё раз непосредственно перед списанием.</p>
                    <p class="app-player-workspace__generation-no-funds" data-player-offer-unavailable role="status"
                        @if($avatarCanGenerate) hidden @endif>
                        {{ $avatarPrice === null ? 'Стоимость генерации временно недоступна.' : 'Недостаточно средств для генерации аватара.' }}
                    </p>
                    <div class="app-player-workspace__generation-topup">
                        <button type="button" class="app-player-workspace__generation-help" data-player-topup-open
                            aria-haspopup="dialog" aria-controls="app-player-topup-dialog">Как пополнить?</button>
                    </div>
                    <div data-player-generation-fields @if(! $avatarCanGenerate) hidden @endif>
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
                            <button type="button" class="app-player-workspace__secondary" data-player-generate
                                @disabled(! $avatarCanGenerate)>Сгенерировать</button>
                        </div>
                    </div>
                    <p class="app-player-workspace__generation-status" data-player-generation-status role="status" aria-live="polite"></p>
                </section>
                <dialog id="app-player-topup-dialog" class="app-player-workspace__topup-dialog" data-player-topup-dialog
                    aria-labelledby="app-player-topup-title">
                    <div class="app-player-workspace__topup-head">
                        <h3 id="app-player-topup-title">Как пополнить баланс?</h3>
                        <button type="button" class="app-player-workspace__secondary" data-player-topup-close>Закрыть</button>
                    </div>
                    <div class="app-player-workspace__topup-body">
                        <p>Существует несколько способов пополнения баланса, а текущий остаток всегда можно посмотреть в <a href="{{ route('account.wallet') }}">кошельке</a>.</p>
                        <div class="app-player-workspace__topup-methods" aria-label="Способы пополнения">
                            <details class="app-player-workspace__topup-method" data-topup-method="tasks" open>
                                <summary>За выполнение заданий <span aria-hidden="true" class="app-player-workspace__topup-chevron">⌄</span></summary>
                                <div class="app-player-workspace__topup-method-content">
                                    <p class="app-player-workspace__topup-note">Бонусы начисляются только после выполнения задания и проверки результата.</p>
                                    @forelse($avatarOffer['bonus_opportunities'] as $opportunity)
                                        <details class="app-player-workspace__topup-task" data-topup-task @if($loop->first) open @endif>
                                            <summary>
                                                <span>{{ $opportunity['title'] }}</span>
                                                <span aria-hidden="true" class="app-player-workspace__topup-chevron">⌄</span>
                                            </summary>
                                            <div class="app-player-workspace__topup-task-content">
                                                <dl class="app-player-workspace__topup-task-properties">
                                                    <div>
                                                        <dt>Вознаграждение:</dt>
                                                        <dd>{{ $formatPoints((int) $opportunity['amount_minor']) }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt>Условие:</dt>
                                                        <dd>{{ $opportunity['description'] }}</dd>
                                                    </div>
                                                </dl>
                                                @if($opportunity['code'] === 'venue_first_approval')
                                                    <button type="button" class="app-player-workspace__primary" data-topup-open-dialog="venue">Добавить площадку</button>
                                                @elseif($opportunity['code'] === 'referral_user_confirmed')
                                                    <button type="button" class="app-player-workspace__primary" data-topup-open-dialog="referral">Пригласить друга</button>
                                                @elseif($opportunity['code'] === 'referral_second_level_user_confirmed')
                                                    <button type="button" class="app-player-workspace__primary" data-topup-open-dialog="referral">Начать с приглашения</button>
                                                @endif
                                            </div>
                                        </details>
                                    @empty
                                        <p class="app-player-workspace__topup-note">Сейчас нет активных бонусных заданий. Они появятся здесь, когда будут включены в каталоге наград.</p>
                                    @endforelse
                                </div>
                            </details>
                            <details class="app-player-workspace__topup-method" data-topup-method="payment">
                                <summary>Пополнение баланса <span aria-hidden="true" class="app-player-workspace__topup-chevron">⌄</span></summary>
                                <div class="app-player-workspace__topup-method-content">
                                    <h4>Пополнение баланса картой</h4>
                                    <p class="app-player-workspace__topup-note">Банковское пополнение пока не подключено. Эта форма не списывает деньги и не начисляет средства.</p>
                                    <label class="app-player-workspace__field" for="app-player-topup-amount">Сумма, ₽
                                        <input id="app-player-topup-amount" type="number" min="1" step="1" value="500" inputmode="numeric">
                                    </label>
                                    <button type="button" class="app-player-workspace__secondary" disabled aria-disabled="true">Оплата картой пока недоступна</button>
                                </div>
                            </details>
                            <details class="app-player-workspace__topup-method" data-topup-method="friend">
                                <summary>Перевод от друга <span aria-hidden="true" class="app-player-workspace__topup-chevron">⌄</span></summary>
                                <div class="app-player-workspace__topup-method-content">
                                    <h4>Запросить перевод у друга</h4>
                                    <p class="app-player-workspace__topup-note">Вы можете запросить перевод у своего друга. Он сам решит, переводить ли бонусы. Запрос не списывает средства.</p>
                                    <form data-player-transfer-request-form data-search-url="{{ route('account.wallet.transfer-recipients') }}" action="{{ route('account.wallet.transfer-requests.store') }}">
                                        <label class="app-player-workspace__field" for="player-transfer-friend">Найти пользователя
                                            <input id="player-transfer-friend" type="search" autocomplete="off" placeholder="Имя, никнейм или логин (от 2 символов)" data-transfer-query>
                                        </label>
                                        <div class="app-player-workspace__transfer-results" role="listbox" aria-label="Найденные пользователи" data-transfer-results hidden></div>
                                        <input type="hidden" name="recipient_user_id" data-transfer-selected>
                                        <p class="app-player-workspace__topup-note">Показываются только подтверждённые пользователи, разрешившие поиск и запросы на перевод.</p>
                                        <button class="app-player-workspace__primary" type="submit" data-transfer-submit disabled>Отправить запрос</button>
                                        <p role="status" aria-live="polite" data-transfer-status></p>
                                    </form>
                                </div>
                            </details>
                        </div>
                    </div>
                </dialog>
                <dialog id="app-player-referral-dialog" class="app-player-workspace__topup-dialog app-player-workspace__subdialog"
                    data-player-referral-dialog aria-labelledby="app-player-referral-title">
                    <div class="app-player-workspace__topup-head">
                        <h3 id="app-player-referral-title">Пригласить друга</h3>
                        <button type="button" class="app-player-workspace__secondary" data-topup-subdialog-close>Закрыть</button>
                    </div>
                    <div class="app-player-workspace__topup-body">
                        <p>Отправьте другу вашу персональную ссылку. Реферальные бонусы начисляются по условиям действующего задания.</p>
                        @if($user->canonical()->isConfirmed())
                            @php $referralUrl = route('referral.entry', ['username' => $user->canonical()->username]); @endphp
                            <div class="app-player-workspace__referral-link">
                                <input type="text" value="{{ $referralUrl }}" readonly aria-label="Реферальная ссылка" data-referral-url>
                                <button type="button" class="app-player-workspace__secondary app-player-workspace__referral-copy" data-referral-copy aria-label="Скопировать реферальную ссылку"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg><span>Скопировать</span></button>
                            </div>
                            <button type="button" class="app-player-workspace__primary" data-referral-share>Пригласить друга</button>
                            <p role="status" aria-live="polite" data-referral-status></p>
                        @else
                            <p>Для работы реферальной ссылки сперва подтвердите аккаунт.</p>
                            <a href="{{ route('account.confirmation') }}" class="app-player-workspace__primary">Подтвердить аккаунт</a>
                        @endif
                    </div>
                </dialog>
                <dialog id="app-player-venue-dialog" class="app-player-workspace__topup-dialog app-player-workspace__subdialog app-player-workspace__venue-dialog"
                    data-player-venue-dialog aria-labelledby="app-player-venue-title">
                    <div class="app-player-workspace__topup-head">
                        <h3 id="app-player-venue-title">Добавить площадку</h3>
                        <button type="button" class="app-player-workspace__secondary" data-topup-subdialog-close>Закрыть</button>
                    </div>
                    <div class="app-player-workspace__topup-body">
                        @include('theme::pages.venues.partials.create-form', [
                            'instance' => 'player-topup',
                            'types' => \App\Modules\Venue\Domain\Enums\VenueTypeEnum::cases(),
                            'metros' => app(\App\Modules\Location\Application\UseCases\ListMetrostationsHandler::class)->handle(),
                        ])
                    </div>
                </dialog>
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
        <dialog class="mskba-modal app-player-workspace__equipment-dialog"
            id="app-player-equipment-dialog" data-player-equipment-dialog
            aria-labelledby="app-player-equipment-dialog-title">
            <div class="mskba-modal__header app-profile__request-header">
                <h2 id="app-player-equipment-dialog-title" tabindex="-1" data-player-equipment-title>Выбор стороны</h2>
                <button type="button" class="app-profile__dialog-close"
                    data-player-equipment-cancel aria-label="Закрыть">×</button>
            </div>
            <div class="mskba-modal__body mskba-scroll app-profile__request-body">
                <p>Укажи, с какой стороны использовать аксессуар. Можно выбрать обе стороны.</p>
                <div class="app-player-workspace__equipment-side-options">
                    <label class="check-label"><input type="checkbox" data-player-equipment-left> Слева</label>
                    <label class="check-label"><input type="checkbox" data-player-equipment-right> Справа</label>
                </div>
            </div>
            <div class="mskba-modal__footer app-profile__request-footer">
                <button type="button" class="button primary" data-player-equipment-apply>Применить</button>
            </div>
        </dialog>
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
