<x-filament-panels::page>
    @include('filament.backoffice.role-detail-styles')
    @php
        $editedUser = $this->editedUser();
        $assignment = $this->assignment();
        $isPlayer = $assignment->role === \App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum::PLAYER;
        $active = $assignment->status === \App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum::ACTIVE;
        $baseUrl = \App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource::getUrl('role', [
            'record' => $editedUser, 'participationRole' => $assignment->id,
        ]);
    @endphp
    <div class="mskba-role-editor">
        <div class="mskba-role-summary">
            <div class="mskba-role-summary-text">
                <a class="text-orange-600 hover:underline" href="{{ \App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource::getUrl('edit', ['record' => $editedUser, 'tab' => 'rol']) }}">
                    К списку ролей пользователя
                </a>
                <span class="mx-2">/</span>
                {{ $assignment->role->label() }} · {{ $active ? 'Активна' : 'Отключена' }}
            </div>
            @if($isPlayer)
                <span class="mskba-role-caption">Данные связаны с аккаунтом #{{ $editedUser->id }} · роль #{{ $assignment->id }}</span>
            @endif
        </div>
        <x-filament::section heading="Параметры роли" description="Редактирование ограничено этой ролью. Все изменения проходят проверку прав и сохраняются с основанием.">
            <div class="mskba-role-form">
                <label for="role-detail-comment" class="mskba-role-label">Комментарий к роли</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="role-detail-comment" wire:model="roleComment" type="text" maxlength="1000" />
                </x-filament::input.wrapper>
                @if($active)
                    <div class="mskba-role-actions">
                        <x-filament::button type="button" size="sm" wire:click="mountAction('saveRoleComment')">Сохранить комментарий</x-filament::button>
                    </div>
                @else
                    <p class="text-sm text-amber-700">Роль отключена: данные доступны для просмотра. Включите роль в карточке пользователя, чтобы возобновить редактирование.</p>
                @endif
            </div>
        </x-filament::section>
        @if($isPlayer)
            @php
                $tabs = [
                    'physical' => 'Физические данные',
                    'game' => 'Игровые характеристики',
                    'teams' => 'Команды',
                    'events' => 'Мероприятия',
                    'games' => 'Игры и статистика',
                ];
            @endphp
            <nav class="mskba-role-tabs" aria-label="Разделы роли игрока">
                @foreach($tabs as $key => $label)
                    <a href="{{ $baseUrl.'?tab='.$key }}"
                        @if($this->tab === $key) aria-current="page" @endif
                        class="mskba-role-tab {{ $this->tab === $key ? 'is-active' : '' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </nav>
            @if($this->tab === 'physical')
                <x-filament::section heading="Физические данные" description="Антропометрия и описание игрока. Эти сведения совпадают с публичной анкетой и не дублируются в административной таблице.">
                    <div class="mskba-role-fields">
                        <div class="mskba-role-field">
                            <label for="player-role-height" class="text-sm font-medium">Рост, см</label>
                            <x-filament::input.wrapper><x-filament::input id="player-role-height" wire:model="height" type="number" min="150" max="220" :disabled="! $active" /></x-filament::input.wrapper>
                        </div>
                        <div class="mskba-role-field">
                            <label for="player-role-weight" class="text-sm font-medium">Вес, кг</label>
                            <x-filament::input.wrapper><x-filament::input id="player-role-weight" wire:model="weight" type="number" min="40" max="140" step="1" :disabled="! $active" /></x-filament::input.wrapper>
                        </div>
                        <div class="mskba-role-field">
                            <label for="player-role-year" class="text-sm font-medium">Год начала занятий</label>
                            <x-filament::input.wrapper><x-filament::input id="player-role-year" wire:model="experienceYear" type="number" :disabled="! $active" /></x-filament::input.wrapper>
                        </div>
                        <div class="mskba-role-field">
                            <label for="player-role-body" class="text-sm font-medium">Телосложение</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="player-role-body" wire:model="bodyType" :disabled="! $active">
                                    <option value="">Не указано</option>
                                    @foreach(\App\Modules\Identity\Domain\Enums\Participation\PlayerBodyTypeEnum::cases() as $body)
                                        <option value="{{ $body->value }}">{{ $body->label() }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                        <div class="mskba-role-field mskba-role-field--wide">
                            <label for="player-role-description" class="text-sm font-medium">Комментарий игрока</label>
                            <x-filament::input.wrapper>
                                <x-filament::input id="player-role-description" wire:model="playerComment" type="text" maxlength="1000" :disabled="! $active" />
                            </x-filament::input.wrapper>
                        </div>
                    </div>
                    @if($active)
                        <div class="mskba-role-actions">
                            <x-filament::button type="button" wire:click="mountAction('savePhysical')">Сохранить физические данные</x-filament::button>
                        </div>
                    @endif
                </x-filament::section>
            @elseif($this->tab === 'game')
                <x-filament::section heading="Игровые позиции" description="Можно указать несколько позиций. Изменения сохраняются с обязательным основанием.">
                    <div class="mskba-role-checklist">
                        @foreach(\App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum::cases() as $position)
                            <label class="mskba-role-check">
                                <input type="checkbox" class="rounded border-gray-300 text-orange-500" wire:model="positions" value="{{ $position->value }}" @disabled(! $active)>
                                <span>{{ $position->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                </x-filament::section>
                <x-filament::section heading="Самооценка навыков" description="Оценки игрока по шкале от 1 до 10. Объективная оценка рассчитывается отдельно из подтверждённой игровой статистики.">
                    <div class="mskba-role-fields">
                        @foreach(\App\Modules\Identity\Domain\Models\Participation\PlayerSelfAssessment::SKILLS as $key => $label)
                            <div class="mskba-role-field">
                                <label class="text-sm font-medium" for="skill-{{ $key }}">{{ $label }}</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input id="skill-{{ $key }}" wire:model="skills.{{ $key }}" type="number" min="1" max="10" :disabled="! $active" />
                                </x-filament::input.wrapper>
                            </div>
                        @endforeach
                    </div>
                    @if($active)
                        <div class="mskba-role-actions"><x-filament::button wire:click="mountAction('saveGame')" type="button">Сохранить игровые характеристики</x-filament::button></div>
                    @endif
                </x-filament::section>
                @php $objective = $editedUser->playerObjectiveAssessment; @endphp
                <x-filament::section heading="Объективная оценка" description="Расчётные показатели изменять здесь нельзя — источником является подтверждённая статистика игр.">
                    @if($objective)
                        <div class="mskba-role-objective-grid">
                            @foreach(\App\Modules\Identity\Domain\Models\Participation\PlayerObjectiveAssessment::SKILLS as $key => $label)
                                <div class="rounded-lg border p-3"><div class="text-xs text-gray-500">{{ $label }}</div><div class="font-semibold">{{ $objective->$key ?? '—' }}</div></div>
                            @endforeach
                        </div>
                        <p class="mt-3 text-sm text-gray-500">Игр в расчёте: {{ $objective->games_count }} · обновлено: {{ $objective->calculated_at?->format('d.m.Y H:i') ?? '—' }}</p>
                    @else
                        <p class="text-sm text-gray-500">Объективная оценка ещё не рассчитана.</p>
                    @endif
                </x-filament::section>
            @elseif($this->tab === 'teams')
                @php $teamNames = $this->teamNames(); @endphp
                <x-filament::section heading="Команды пользователя" description="Здесь учитываются действующие и исторические договоры членства, в том числе ожидающие приглашения.">
                    <div class="mskba-role-table-scroll">
                        <table class="w-full text-sm">
                            <thead><tr class="border-b text-left"><th class="p-3">Команда</th><th class="p-3">Статус</th><th class="p-3">Роль</th><th class="p-3">Договор</th></tr></thead>
                            <tbody>
                            @forelse($this->teamMemberships() as $membership)
                                <tr class="border-b" wire:key="team-membership-{{ $membership->id }}">
                                    <td class="p-3">{{ $teamNames[$membership->scope_id] ?? 'Команда #'.$membership->scope_id }}</td>
                                    <td class="p-3">{{ $membership->invitation_status?->label() ?? '—' }}</td>
                                    <td class="p-3">{{ implode(', ', $membership->sportRoleValues()) ?: '—' }}</td>
                                    <td class="p-3">#{{ $membership->contract_id }} · {{ $membership->contract?->status?->value ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="p-4 text-gray-500">Пользователь ещё не состоит в командах.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
                <x-filament::section heading="Добавить в команду" description="Поиск действующих команд. Прямое административное назначение доступно только суперадминистратору, требует основания и записывается в аудит.">
                    @if($active && auth('web')->user()?->canonical()->system_role === \App\Modules\Identity\Domain\Enums\UserSystemRoleEnum::SUPERADMIN)
                        <label for="team-search" class="mskba-role-label">Поиск команды по названию</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="team-search" wire:model.live.debounce.350ms="teamSearch" placeholder="Введите минимум 2 символа…" />
                        </x-filament::input.wrapper>
                        <div class="mskba-role-search-results">
                            @forelse($this->searchedTeams() as $team)
                                <div class="mskba-role-search-result" wire:key="available-team-{{ $team->id }}">
                                    <span>{{ $team->name }} <span class="text-xs text-gray-500">#{{ $team->id }}</span></span>
                                    <x-filament::button color="gray" size="sm" type="button" wire:click="mountAction('addToTeam', { team_id: {{ $team->id }} })">Включить игрока</x-filament::button>
                                </div>
                            @empty
                                @if(mb_strlen(trim($this->teamSearch)) >= 2)<p class="text-sm text-gray-500">Подходящих команд нет.</p>@endif
                            @endforelse
                        </div>
                    @else
                        <p class="text-sm text-gray-500">Прямое включение недоступно: требуется активная роль игрока и системная роль суперадминистратора.</p>
                    @endif
                </x-filament::section>
            @elseif($this->tab === 'events')
                <x-filament::section heading="Участие в мероприятиях" description="Связанные записи участия показывают фактический статус, а не только мероприятия, созданные пользователем.">
                    <div class="mskba-role-table-scroll">
                        <table class="w-full text-sm">
                            <thead><tr class="border-b text-left"><th class="p-3">Мероприятие</th><th class="p-3">Начало</th><th class="p-3">Статус</th><th class="p-3">Роль</th><th class="p-3">Площадка</th></tr></thead>
                            <tbody>
                            @forelse($this->eventParticipations() as $participation)
                                <tr class="border-b" wire:key="event-participation-{{ $participation->id }}">
                                    <td class="p-3">{{ $participation->event?->title ?? 'Мероприятие #'.$participation->event_id }}</td>
                                    <td class="p-3">{{ $participation->event?->starts_at?->format('d.m.Y H:i') ?? '—' }}</td>
                                    <td class="p-3">{{ $participation->status?->label() ?? '—' }}</td>
                                    <td class="p-3">{{ implode(', ', $participation->participation_roles ?? []) ?: $participation->role?->value ?? '—' }}</td>
                                    <td class="p-3">{{ $participation->event?->venue?->name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="p-4 text-gray-500">Участий пока нет.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @elseif($this->tab === 'games')
                <x-filament::section heading="Проведённые игры и статистика" description="Только завершённые игры из составов или статистики пользователя. Результаты и протоколы нельзя изменять через редактор профиля.">
                    <div class="mskba-role-table-scroll">
                        <table class="w-full text-sm">
                            <thead><tr class="border-b text-left"><th class="p-3">Игра</th><th class="p-3">Завершена</th><th class="p-3">Статистика</th><th class="p-3">Очки</th><th class="p-3">Подборы</th><th class="p-3">Передачи</th><th class="p-3">Минуты</th></tr></thead>
                            <tbody>
                            @forelse($this->completedGames() as $game)
                                @php
                                    $stats = $game->playerStatistics->first();
                                    $points = $stats?->points($game->scoring_type ?? \App\Modules\Event\Domain\Enums\GameScoringTypeEnum::STREETBALL);
                                @endphp
                                <tr class="border-b" wire:key="played-game-{{ $game->id }}">
                                    <td class="p-3">{{ $game->title ?: $game->event?->title ?: 'Игра #'.$game->id }} <span class="block text-xs text-gray-500">#{{ $game->id }} · {{ $game->formatLabel() }}</span></td>
                                    <td class="p-3">{{ $game->completed_at?->format('d.m.Y H:i') ?? '—' }}</td>
                                    <td class="p-3">{{ $game->statistics_status?->label() ?? '—' }}</td>
                                    <td class="p-3">{{ $points ?? '—' }}</td>
                                    <td class="p-3">{{ $stats ? $stats->offensive_rebounds + $stats->defensive_rebounds : '—' }}</td>
                                    <td class="p-3">{{ $stats?->assists ?? '—' }}</td>
                                    <td class="p-3">{{ $stats?->minutes ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="p-4 text-gray-500">Завершённых игр пока нет.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endif
        @else
            <x-filament::section heading="Параметры роли {{ $assignment->role->label() }}">
                <p class="text-sm text-gray-500">Для этой роли пока нет отдельной специализированной анкеты. Изменить можно комментарий и состояние роли в карточке пользователя; другие данные не выдумываются.</p>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
