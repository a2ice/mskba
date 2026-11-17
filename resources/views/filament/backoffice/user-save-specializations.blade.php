@if(auth('web')->user() && app(\App\Modules\Identity\Application\Services\StaffSpecializationManager::class)->mayManage(auth('web')->user(), $this->getRecord()))
    <div class="flex justify-end">
        <x-filament::button type="button" icon="heroicon-o-check" wire:click="mountAction('saveSpecializationsInline')">
            Сохранить специализации
        </x-filament::button>
    </div>
@endif
