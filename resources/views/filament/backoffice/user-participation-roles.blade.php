@php
    $roles = $this->getRecord()->participationRoles(false)->orderBy('id')->get();
@endphp
<div class="space-y-5">
    <x-filament::section heading="Роли участия" description="Данные отключённой роли сохраняются. Повторное включение восстановит прежний профиль.">
        <div class="mb-4">
            <x-filament::button type="button" icon="heroicon-o-plus" wire:click="mountAction('addParticipationRole')">
                Добавить роль
            </x-filament::button>
        </div>
        <div class="mskba-bo-table mskba-bo-table--cards">
            <table class="mskba-bo-table__table">
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
                        <td data-label="Роль">
                            <a class="fi-btn fi-color-gray fi-size-sm" href="{{ \App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource::getUrl('role', ['record' => $this->getRecord(), 'participationRole' => $role->id]) }}">{{ $role->role->label() }}</a>
                        </td>
                        <td data-label="Статус">{{ $role->status->label() }}</td>
                        <td data-label="Дата назначения">{{ $role->assigned_at?->format('d.m.Y') ?? '—' }}</td>
                        <td data-label="Действия">
                            <div class="mskba-bo-table__actions">
                                <a class="fi-btn fi-color-gray fi-size-sm" href="{{ \App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource::getUrl('role', ['record' => $this->getRecord(), 'participationRole' => $role->id]) }}">Редактировать</a>
                                <x-filament::button color="{{ $role->status === \App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum::ACTIVE ? 'danger' : 'success' }}" size="sm" type="button" wire:click="mountAction('toggleParticipationRole', { role_id: {{ $role->id }} })">
                                    {{ $role->status === \App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum::ACTIVE ? 'Отключить' : 'Включить' }}
                                </x-filament::button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="mskba-bo-table__empty" colspan="4">Роли пока не назначены.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</div>
