<div data-player-generation-fields @if(! $avatarCanGenerate) hidden @endif>
    <fieldset class="app-player-workspace__equipment">
        <legend>Фотографии лица для генерации</legend>
        <p>Загрузите анфас и хотя бы один профиль — слева или справа.</p>
        <div class="app-player-workspace__face-uploads">
            @foreach(['front' => 'Анфас', 'left' => 'Слева', 'right' => 'Справа'] as $slot => $label)
                <label class="app-player-workspace__face-upload" data-face-upload="{{ $slot }}">
                    <input type="file" accept="image/jpeg,image/png,image/webp" data-player-face-slot="{{ $slot }}" aria-label="Фото {{ $label }}">
                    <span class="app-player-workspace__face-icon" aria-hidden="true">
                        <svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
                            @if($slot === 'front')
                                <path d="M22 16Q32 6 42 16V34Q40 48 32 50Q24 48 22 34Z"/>
                            @elseif($slot === 'left')
                                <path d="M40 13Q22 5 20 23V32L15 38L24 41L26 51Q40 56 44 39V23Q44 17 40 13Z"/>
                            @else
                                <path d="M24 13Q42 5 44 23V32L49 38L40 41L38 51Q24 56 20 39V23Q20 17 24 13Z"/>
                            @endif
                        </svg>
                        <span class="app-player-workspace__face-tick">✓</span>
                    </span>
                    <span>{{ $label }}</span>
                    <small data-face-filename>Добавить фото</small>
                </label>
            @endforeach
        </div>
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
        <div class="app-player-workspace__logo-modes">
            <span>Логотип на форме</span>
            <label><input type="radio" name="generation_logo_enabled" value="no" data-player-logo-enabled checked> Без логотипа</label>
            <label><input type="radio" name="generation_logo_enabled" value="yes" data-player-logo-enabled> С логотипом</label>
        </div>
        <div class="app-player-workspace__logo-options" data-player-logo-options hidden>
            <label><input type="radio" name="generation_logo_source" value="team" data-player-logo-source checked> Логотип команды</label>
            <label><input type="radio" name="generation_logo_source" value="custom" data-player-logo-source> Свой логотип</label>
            <button type="button" class="app-player-workspace__secondary" data-player-logo-picker-open hidden>Выбрать логотип</button>
            <span data-player-logo-selected></span>
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