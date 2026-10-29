@if(auth('web')->user()?->canonical()->system_role === \App\Modules\Identity\Domain\Enums\UserSystemRoleEnum::SUPERADMIN)
    <div class="flex justify-end">
        <x-filament::button type="button" icon="heroicon-o-check" wire:click="mountAction('saveGeneralInline')">
            Сохранить изменения
        </x-filament::button>
    </div>
@endif
