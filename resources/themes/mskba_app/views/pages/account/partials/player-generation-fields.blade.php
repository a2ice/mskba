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
            <label class="app-player-workspace__field">Форма команды
                <span class="select-control">
                    <select data-player-generation-team>
                        @forelse($playerTeams as $team)
                            <option value="{{ $team->id }}">{{ $team->name }}</option>
                        @empty
                            <option value="">Нейтральная форма</option>
                        @endforelse
                        <option value="__custom__">Создать свою форму</option>
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
        </div>
        <div class="app-player-workspace__logo-control">
            <label class="check-label app-player-workspace__logo-toggle">
                <input type="checkbox" data-player-logo-enabled
                    @checked(($character['generation_logo_mode'] ?? '') === 'custom')>
                <span>С логотипом</span>
            </label>
            <div class="app-player-workspace__logo-options" data-player-logo-options
                data-saved-logo-id="{{ (int) ($character['generation_custom_logo_id'] ?? 0) }}"
                @if(($character['generation_logo_mode'] ?? '') !== 'custom') hidden @endif>
                <span class="app-player-workspace__logo-preview">
                    <img src="{{ asset('images/team-placeholder.webp') }}" data-player-logo-preview
                        data-logo-placeholder="{{ asset('images/team-placeholder.webp') }}"
                        alt="Логотип не выбран" width="35" height="35" decoding="async">
                </span>
                <button type="button" class="app-player-workspace__secondary" data-player-logo-picker-open
                    aria-haspopup="dialog" aria-controls="app-player-logo-dialog">Выбрать логотип</button>
                <span class="app-player-workspace__logo-selected" data-player-logo-selected aria-live="polite">Логотип не выбран</span>
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
        <button type="button" class="app-player-workspace__secondary" data-player-generate @disabled(! $avatarCanGenerate)>Сгенерировать</button>
    </div>
</div>