<x-filament-panels::page>
    <x-filament::section heading="Публичные контакты MSKBA" icon="heroicon-o-phone">
        <p class="text-sm">Единые контакты для приглашений, писем, футера и помощи. Скрытые или пустые контакты не публикуются. Юридический email, пароли, токены и параметры отправки почты не изменяются.</p>
        @if(session('portal_contacts_status'))
            <p role="status">{{ session('portal_contacts_status') }}</p>
        @endif
        @if($errors->any())
            <div role="alert">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif
        <form method="POST" action="{{ route('backoffice.portal-contacts.save') }}" style="display:grid;gap:16px;max-width:44rem">
            @csrf
            @foreach([
                'email' => ['email', 'Email', 'email', 'support@example.test'],
                'phone' => ['phone', 'Телефон', 'tel', '+7 (900) 000-00-00'],
                'telegram' => ['telegram_url', 'Telegram', 'url', 'https://t.me/mskba'],
                'vk' => ['vk_url', 'ВКонтакте', 'url', 'https://vk.com/mskba'],
            ] as $channel => [$field, $label, $type, $placeholder])
                <div style="display:grid;gap:8px">
                    <label style="display:grid;gap:6px">
                        {{ $label }}
                        <x-filament::input.wrapper>
                            <x-filament::input :type="$type" :name="$field" :placeholder="$placeholder" :value="old($field, $contacts[$field] ?? '')" />
                        </x-filament::input.wrapper>
                    </label>
                    <input type="hidden" name="{{ $channel }}_public" value="0">
                    <label style="display:flex;align-items:center;gap:8px">
                        <input class="fi-checkbox-input" type="checkbox" name="{{ $channel }}_public" value="1" @checked(old($channel.'_public', $contacts[$channel.'_public']))>
                        Показывать {{ $label }}
                    </label>
                </div>
            @endforeach
            <div><x-filament::button type="submit">Сохранить</x-filament::button></div>
        </form>
    </x-filament::section>
    <x-filament::section heading="Интеграции">
        <p class="text-sm">Адреса Telegram и ВКонтакте синхронны с настройками интеграций. Публичность не влияет на работу ботов и публикаций.</p>
        <x-filament::button tag="a" :href="url('/backoffice/social-integrations')" color="gray">Настройки социальных сетей</x-filament::button>
    </x-filament::section>
</x-filament-panels::page>
