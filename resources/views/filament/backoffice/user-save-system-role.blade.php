@if(auth('web')->user()?->can('manage-user-system-role', $this->getRecord()))
    <div class="flex justify-end">
        <x-filament::button type="button" icon="heroicon-o-check" wire:click="mountAction('saveSystemRoleInline')">
            Сохранить системную роль
        </x-filament::button>
    </div>
@endif
