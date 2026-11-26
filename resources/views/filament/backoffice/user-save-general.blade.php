@php
    $target = $editedUser?->fresh();
    $missing = $target?->missingAccountConfirmationRequirements() ?? [];
    $labels = [
        'first_name' => 'Имя',
        'last_name' => 'Фамилия',
        'birth_date' => 'Дата рождения',
        'gender' => 'Пол',
        'primary_verified_contact' => 'Подтверждённый основной контакт',
    ];
@endphp

@if($target)
    <x-filament::section heading="Условия подтверждения аккаунта" description="Проверяются сохранённые данные профиля и контактов.">
        <div class="space-y-2">
            @foreach($labels as $key => $label)
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span>{{ $label }}</span>
                    <x-filament::badge :color="in_array($key, $missing, true) ? 'warning' : 'success'">
                        {{ in_array($key, $missing, true) ? 'Не выполнено' : 'Выполнено' }}
                    </x-filament::badge>
                </div>
            @endforeach
        </div>
        @if($missing !== [] && $target->isConfirmed())
            <p class="mt-3 text-sm text-gray-500">Аккаунт имеет подтверждённый статус, но не все условия выполнены. Проверьте историю изменений.</p>
        @elseif($missing !== [])
            <p class="mt-3 text-sm text-gray-500">Для обычного подтверждения выполните все условия.</p>
        @elseif(!$target->isConfirmed())
            <p class="mt-3 text-sm text-gray-500">Все условия обычного подтверждения выполнены.</p>
        @endif
    </x-filament::section>
@endif

@if(auth('web')->user()?->canonical()->system_role === \App\Modules\Identity\Domain\Enums\UserSystemRoleEnum::SUPERADMIN)
    <div class="flex justify-end">
        <x-filament::button type="button" icon="heroicon-o-check" wire:click="mountAction('saveGeneralInline')">
            Сохранить изменения
        </x-filament::button>
    </div>
@endif
