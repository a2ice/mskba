<x-filament-panels::page>
    <x-filament::section
        heading="Выпустить токен для записи черновиков"
        description="Системный пользователь dev · только project-updates:write · срок действия 24 часа."
        icon="heroicon-o-key"
    >
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
            Этот токен не даёт доступа к другим API или публикации новостей.
            Храни его в защищённых секретах клиента, не отправляй в переписку или GitHub.
            Для каждой среды требуется свой токен.
        </p>
        <form wire:submit="issue" style="display:grid;gap:16px;max-width:36rem">
            <div style="display:grid;gap:8px">
                <label for="updates-api-password">Пароль суперадминистратора</label>
                <x-filament::input.wrapper :valid="! $errors->has('password')">
                    <x-filament::input
                        id="updates-api-password"
                        type="password"
                        autocomplete="current-password"
                        wire:model="password"
                    />
                </x-filament::input.wrapper>
                @error('password')
                    <span role="alert">{{ $message }}</span>
                @enderror
            </div>
            <div><x-filament::button type="submit">Выпустить токен</x-filament::button></div>
        </form>
    </x-filament::section>

    @if($plainTextToken !== null)
        <x-filament::section heading="Токен выпущен" icon="heroicon-o-check-circle">
            <p>Действует до {{ $expiresAt }}. Повторно посмотреть токен нельзя.</p>
            <x-filament::input.wrapper>
                <x-filament::input type="text" :value="$plainTextToken" readonly autocomplete="off" spellcheck="false" />
            </x-filament::input.wrapper>
            <p class="text-sm">Используй только в защищённых настройках агента. API: <code>POST /api/v1/internal/project-updates</code>.</p>
        </x-filament::section>
    @endif

    <x-filament::section heading="Отозвать доступ" icon="heroicon-o-shield-exclamation">
        <x-filament::button type="button" color="gray" wire:click="revokeTokens"
            wire:confirm="Отозвать все действующие токены журнала обновлений dev?">
            Отозвать все токены журнала
        </x-filament::button>
    </x-filament::section>
</x-filament-panels::page>
