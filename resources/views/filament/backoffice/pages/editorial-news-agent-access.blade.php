<x-filament-panels::page>
    <x-filament::section
        heading="Выпустить токен для новостей"
        description="Системный пользователь dev · срок действия 24 часа."
        icon="heroicon-o-key"
    >
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
            По умолчанию токен позволяет только создавать и читать черновики обычных материалов. Публикация требует явного разрешения ниже.
            Храни его в защищённых секретах клиента, не отправляй в переписку или GitHub.
            Для каждой среды требуется свой токен.
        </p>
        <form wire:submit="issue" style="display:grid;gap:16px;max-width:36rem">
            <div style="display:grid;gap:8px">
                <label for="editorial-api-password">Пароль суперадминистратора</label>
                <x-filament::input.wrapper :valid="! $errors->has('password')">
                    <x-filament::input
                        id="editorial-api-password"
                        type="password"
                        autocomplete="current-password"
                        wire:model="password"
                    />
                </x-filament::input.wrapper>
                @error('password')
                    <span role="alert">{{ $message }}</span>
                @enderror
            </div>
            <label style="display:flex;align-items:center;gap:8px">
                <x-filament::input.checkbox wire:model="allowPublish" />
                Разрешить публикацию новостей на сайте
            </label>
            <div><x-filament::button type="submit">Выпустить токен</x-filament::button></div>
        </form>
    </x-filament::section>

    @if($plainTextToken !== null)
        <x-filament::section heading="Токен выпущен" icon="heroicon-o-check-circle">
            <p>Действует до {{ $expiresAt }}. Повторно посмотреть токен нельзя.</p>
            <x-filament::input.wrapper>
                <x-filament::input type="text" :value="$plainTextToken" readonly autocomplete="off" spellcheck="false" />
            </x-filament::input.wrapper>
            <p class="text-sm">Используй только в защищённых настройках агента. API: <code>POST /api/v1/internal/editorial-news</code>.</p>
        </x-filament::section>
    @endif

    <x-filament::section heading="Отозвать доступ" icon="heroicon-o-shield-exclamation">
        <x-filament::button type="button" color="gray" wire:click="revokeTokens"
            wire:confirm="Отозвать все действующие токены редакционных новостей dev?">
            Отозвать все токены новостей
        </x-filament::button>
    </x-filament::section>
</x-filament-panels::page>
