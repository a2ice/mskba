@php
    $roles = $this->getRecord()->participationRoles(false)->orderBy('id')->get();
    $selected = $roles->firstWhere('id', $this->selectedParticipationRoleId);
@endphp
<div class="space-y-5">
    <x-filament::section heading="Роли участия" description="Данные отключённой роли сохраняются. Повторное включение восстановит прежний профиль.">
        <div class="mb-4">
            <x-filament::button type="button" icon="heroicon-o-plus" wire:click="mountAction('addParticipationRole')">
                Добавить роль
            </x-filament::button>
        </div>
        <div class="overflow-x-auto">
            <table style="width:100%;border-collapse:collapse;text-align:left">
                <thead>
                <tr style="border-bottom:1px solid #d1d5db">
                    <th style="padding:12px">Роль</th>
                    <th style="padding:12px">Статус</th>
                    <th style="padding:12px">Дата назначения</th>
                    <th style="padding:12px">Действия</th>
                </tr>
                </thead>
                <tbody>
                @forelse($roles as $role)
                    <tr wire:key="participation-role-row-{{ $role->id }}" style="border-bottom:1px solid #e5e7eb">
                        <td style="padding:12px">
                            <x-filament::button color="gray" size="sm" type="button" wire:click="selectParticipationRole({{ $role->id }})">{{ $role->role->label() }}</x-filament::button>
                        </td>
                        <td style="padding:12px">{{ $role->status->label() }}</td>
                        <td style="padding:12px">{{ $role->assigned_at?->format('d.m.Y') ?? '—' }}</td>
                        <td style="padding:12px">
                            <div class="flex flex-wrap gap-2">
                                <x-filament::button color="gray" size="sm" type="button" wire:click="selectParticipationRole({{ $role->id }})">Редактировать</x-filament::button>
                                <x-filament::button color="{{ $role->status === \App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum::ACTIVE ? 'danger' : 'success' }}" size="sm" type="button" wire:click="mountAction('toggleParticipationRole', { role_id: {{ $role->id }} })">
                                    {{ $role->status === \App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum::ACTIVE ? 'Отключить' : 'Включить' }}
                                </x-filament::button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td style="padding:18px" colspan="4">Роли пока не назначены.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
    @if($selected)
        <x-filament::section heading="Редактирование роли: {{ $selected->role->label() }}" description="Параметры сохраняются после подтверждения основания. Другие роли пользователя не меняются.">
            <div class="space-y-4">
                @if($selected->status !== \App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum::ACTIVE)
                    <p>Эта роль отключена. Сначала включите её, чтобы редактировать профиль.</p>
                @else
                    <div>
                        <label for="inline-role-comment" class="block mb-2">Комментарий к роли</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="inline-role-comment" wire:model="roleComment" type="text" />
                        </x-filament::input.wrapper>
                    </div>
                    @if($selected->role === \App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum::PLAYER)
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div><label for="inline-role-height">Рост, см</label><x-filament::input.wrapper><x-filament::input id="inline-role-height" wire:model="rolePlayerHeight" type="number" min="150" max="220" /></x-filament::input.wrapper></div>
                            <div><label for="inline-role-weight">Вес, кг</label><x-filament::input.wrapper><x-filament::input id="inline-role-weight" wire:model="rolePlayerWeight" type="number" min="40" max="140" /></x-filament::input.wrapper></div>
                            <div><label for="inline-role-experience">Год начала занятий</label><x-filament::input.wrapper><x-filament::input id="inline-role-experience" wire:model="rolePlayerExperience" type="number" /></x-filament::input.wrapper></div>
                            <div>
                                <label for="inline-role-body">Телосложение</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select id="inline-role-body" wire:model="rolePlayerBodyType">
                                        <option value="">Не задано</option>
                                        @foreach(\App\Modules\Identity\Domain\Enums\Participation\PlayerBodyTypeEnum::cases() as $type)
                                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </div>
                        </div>
                        <div><label for="inline-player-comment">Комментарий игрока</label>
                            <x-filament::input.wrapper><x-filament::input id="inline-player-comment" wire:model="rolePlayerComment" type="text" /></x-filament::input.wrapper>
                        </div>
                    @else
                        <p>Для этой роли отдельная специализированная анкета пока не предусмотрена. Можно изменить комментарий или состояние роли.</p>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        <x-filament::button type="button" wire:click="mountAction('saveParticipationRoleInline')">Сохранить роль</x-filament::button>
                        <x-filament::button color="danger" type="button" wire:click="mountAction('toggleParticipationRole', { role_id: {{ $selected->id }} })">Отключить роль</x-filament::button>
                    </div>
                @endif
            </div>
        </x-filament::section>
    @endif
</div>
