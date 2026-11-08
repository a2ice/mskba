<div data-player-generation-fields @if(! $avatarCanGenerate) hidden @endif>
    <fieldset class="app-player-workspace__equipment">
        <legend>Фотографии лица для генерации</legend>
        <p>Загрузите анфас и хотя бы один профиль — слева или справа. Фото сохраняются и могут быть заменены или удалены.</p>
        <div class="app-player-workspace__face-uploads">
            @foreach(['front' => 'Анфас', 'left' => 'Слева', 'right' => 'Справа'] as $slot => $label)
                <div class="app-player-workspace__face-upload" data-face-upload="{{ $slot }}">
                    <label class="app-player-workspace__face-select">
                        <input type="file" accept="image/jpeg,image/png,image/webp" data-player-face-slot="{{ $slot }}" aria-label="Загрузить или заменить фото: {{ $label }}">
                        <span class="app-player-workspace__face-icon">
                            <img src="{{ asset('images/blank/avatar/'.$slot.'-'.$gender.'.png') }}"
                                data-face-photo data-face-placeholder="{{ asset('images/blank/avatar/'.$slot.'-'.$gender.'.png') }}"
                                alt="" width="94" height="94" decoding="async">
                            <span class="app-player-workspace__face-tick" aria-label="Фотография использована в успешной генерации">✓</span>
                        </span>
                        <span>{{ $label }}</span>
                        <small data-face-filename>Добавить фото</small>
                    </label>
                    <button type="button" class="app-player-workspace__face-delete"
                        data-face-delete aria-label="Удалить фото {{ $label }}" hidden>Удалить фото</button>
                    <small class="app-player-workspace__face-feedback" data-face-feedback role="status" aria-live="polite" hidden></small>
                </div>
            @endforeach
        </div>
        <p class="app-player-workspace__face-group-feedback" data-player-face-group-feedback role="status" aria-live="polite" hidden></p>
    </fieldset>
    <fieldset class="app-player-workspace__equipment">
        <legend>Форма и кроссовки</legend>
        <div class="app-player-workspace__fields">
            @php
                $uniformSource = $character['generation_uniform_source'] ?? (!empty($character['generation_team_id']) ? 'team' : 'neutral');
                $uniformDesigns = $playerUniformDesigns ?? collect();
                $savedDesignId = (int) ($character['generation_uniform_design_id'] ?? 0);
                if ($uniformSource === 'team' && $playerTeams->isEmpty()) $uniformSource = 'neutral';
                if ($uniformSource === 'mine' && ! $uniformDesigns->contains('id', $savedDesignId)) $uniformSource = 'neutral';
            @endphp
            <label class="app-player-workspace__field">Форма
                <span class="select-control">
                    <select data-player-uniform-source>
                        <option value="neutral" @selected($uniformSource === 'neutral')>Нейтральная форма</option>
                        <option value="team" @selected($uniformSource === 'team') @disabled($playerTeams->isEmpty()) @if($playerTeams->isEmpty()) hidden @endif>Форма команды</option>
                        <option value="mine" @selected($uniformSource === 'mine') @disabled($uniformDesigns->isEmpty()) @if($uniformDesigns->isEmpty()) hidden @endif>Моя форма</option>
                        <option value="__create__">Создать свою форму</option>
                    </select>
                    @include('account.partials.native-select-chevron')
                </span>
            </label>
            <label class="app-player-workspace__field">Кроссовки
                <span class="select-control">
                    <select name="character[shoes]" form="player-physical-form">
                        @foreach($shoesOptions as $value => $label)
                            <option value="{{ $value }}" @selected(old('character.shoes',$character['shoes'] ?? 'white') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @include('account.partials.native-select-chevron')
                </span>
            </label>
            <div class="app-player-workspace__field" data-player-uniform-team-field
                @if($uniformSource !== 'team') hidden @endif>
                <label for="player-generation-team-select">Команда</label>
                <div class="app-player-workspace__uniform-design-controls">
                    <span class="select-control">
                        <select id="player-generation-team-select" data-player-generation-team
                            @if($uniformSource !== 'team') disabled @endif required>
                            <option value="">Выберите команду</option>
                            @foreach($playerTeams as $team)
                                <option value="{{ $team->id }}"
                                    @selected((int) ($character['generation_team_id'] ?? 0) === (int) $team->id)
                                    data-team-logo-url="{{ $team->logo?->publicUrl() }}"
                                    data-team-name="{{ $team->name }}">{{ $team->name }}</option>
                            @endforeach
                        </select>
                        @include('account.partials.native-select-chevron')
                    </span>
                    <button type="button" class="app-player-workspace__secondary"
                        data-player-team-uniform-view aria-haspopup="dialog"
                        aria-controls="mskba-uniform-dialog"
                        @disabled($uniformSource !== 'team' || empty($character['generation_team_id']))>Посмотреть</button>
                </div>
            </div>
            <div class="app-player-workspace__field" data-player-uniform-design-field
                @if($uniformSource !== 'mine') hidden @endif>
                <label for="player-generation-uniform-design">Мои формы</label>
                <div class="app-player-workspace__uniform-design-controls">
                    <span class="select-control">
                        <select id="player-generation-uniform-design" data-player-generation-uniform-design
                            @if($uniformSource !== 'mine') disabled @endif required>
                            <option value="">Выберите форму</option>
                            @foreach($uniformDesigns as $design)
                                <option value="{{ $design->id }}" @selected($savedDesignId === (int) $design->id)>{{ $design->name }}</option>
                            @endforeach
                        </select>
                        @include('account.partials.native-select-chevron')
                    </span>
                    <button type="button" class="app-player-workspace__secondary"
                        data-player-uniform-view aria-haspopup="dialog"
                        aria-controls="mskba-uniform-dialog"
                        @disabled($uniformSource !== 'mine' || ! $savedDesignId)>Посмотреть</button>
                </div>
            </div>
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
                    <button type="button" class="app-player-workspace__equipment-edit" data-player-equipment-edit
                        @if(!($hasLeft || $hasRight)) hidden @endif aria-label="Выбрать стороны: {{ $option['name'] }}">Изменить</button>
                    <span data-player-equipment-values>
                        @if($hasLeft)<input type="hidden" form="player-physical-form" name="character[attributes][]" value="{{ $option['left'] }}">@endif
                        @if($hasRight)<input type="hidden" form="player-physical-form" name="character[attributes][]" value="{{ $option['right'] }}">@endif
                    </span>
                </div>
            @endforeach
            <label class="check-label app-player-workspace__equipment-toggle app-player-workspace__equipment-headband">
                <input type="checkbox" form="player-physical-form" name="character[attributes][]" value="headband"
                    @checked(in_array('headband', $selectedEquipment, true))>
                <span>Повязка на голову</span>
            </label>
        </div>
    </fieldset>
    <div class="app-player-workspace__actions">
        <button type="button" class="app-player-workspace__secondary app-player-workspace__generate-button"
            data-player-generate aria-busy="false" @disabled(! $avatarCanGenerate)>
            <span class="app-player-workspace__generate-spinner" data-player-generate-spinner aria-hidden="true" hidden></span>
            <span>Сгенерировать</span>
        </button>
    </div>
</div>