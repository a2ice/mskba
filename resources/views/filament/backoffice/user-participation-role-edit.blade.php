<div>
    <x-filament::button
        type="button"
        color="gray"
        size="sm"
        wire:click="mountAction('editParticipationRole', { role_id: {{ (int) $participationRoleId }} })"
    >
        Изменить статус и комментарий роли
    </x-filament::button>
</div>
