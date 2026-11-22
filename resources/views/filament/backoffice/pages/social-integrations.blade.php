<x-filament-panels::page>
    @php
        $settings = app(\App\Modules\Portal\Application\Services\SocialIntegrationSettings::class);
        $telegram = $settings->display('telegram');
        $vk = $settings->display('vk');
    @endphp
    @if(session('social_status'))
        <p role="status">{{ session('social_status') }}</p>
    @endif
    @if($errors->any())
        <div role="alert">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <x-filament::section heading="Telegram" icon="heroicon-o-chat-bubble-left-right">
        <form method="POST" action="{{ route('backoffice.social-integrations.save', 'telegram') }}" style="display:grid;gap:16px;max-width:44rem" autocomplete="off">
            @csrf
            @foreach(['bot_username'=>'Имя бота', 'bot_domain'=>'Домен бота / Mini App', 'main_chat_id'=>'ID основного чата', 'community_url'=>'Адрес сообщества'] as $field => $label)
                <label style="display:grid;gap:6px">
                    {{ $label }}
                    <x-filament::input.wrapper>
                        <x-filament::input name="{{ $field }}" :value="$telegram[$field] ?? ''" />
                    </x-filament::input.wrapper>
                </label>
            @endforeach
            <label style="display:grid;gap:6px">
                Токен бота
                <x-filament::input.wrapper>
                    <x-filament::input type="password" name="bot_token" autocomplete="new-password" :placeholder="$telegram['bot_token'] ? '•••••••• (сохранён)' : 'Введите токен'" />
                </x-filament::input.wrapper>
            </label>
            <p class="text-sm">Webhook: {{ $telegram['webhook_secret'] ? 'секрет настроен' : 'секрет не задан' }}. Пустой токен оставляет действующее значение.</p>
            <label style="display:grid;gap:6px">
                Пароль суперадминистратора
                <x-filament::input.wrapper>
                    <x-filament::input type="password" name="password" autocomplete="current-password" required />
                </x-filament::input.wrapper>
            </label>
            <div><x-filament::button type="submit">Сохранить</x-filament::button></div>
        </form>
        <form method="POST" action="{{ route('backoffice.social-integrations.check', 'telegram') }}" class="mt-4">
            @csrf
            <x-filament::button type="submit" color="gray">Проверить подключение</x-filament::button>
        </form>
    </x-filament::section>

    <x-filament::section heading="ВКонтакте" icon="heroicon-o-share">
        <form method="POST" action="{{ route('backoffice.social-integrations.save', 'vk') }}" style="display:grid;gap:16px;max-width:44rem" autocomplete="off">
            @csrf
            <label style="display:grid;gap:6px">
                Адрес сообщества
                <x-filament::input.wrapper>
                    <x-filament::input type="url" name="community_url" :value="$vk['community_url'] ?? ''" required />
                </x-filament::input.wrapper>
            </label>
            <label style="display:grid;gap:6px">
                ID сообщества
                <x-filament::input.wrapper>
                    <x-filament::input type="number" name="group_id" min="1" :value="$vk['group_id'] ?: ''" />
                </x-filament::input.wrapper>
            </label>
            @foreach(['community_token'=>'Ключ доступа сообщества', 'mini_app_secret'=>'Защищённый ключ Mini App'] as $field => $label)
                <label style="display:grid;gap:6px">
                    {{ $label }}
                    <x-filament::input.wrapper>
                        <x-filament::input type="password" name="{{ $field }}" autocomplete="new-password" :placeholder="$vk[$field] ? '•••••••• (сохранён)' : 'Введите токен'" />
                    </x-filament::input.wrapper>
                </label>
            @endforeach
            <label style="display:grid;gap:6px">
                ID Mini App
                <x-filament::input.wrapper>
                    <x-filament::input type="number" name="mini_app_id" min="1" :value="$vk['mini_app_id']" required />
                </x-filament::input.wrapper>
            </label>
            <p class="text-sm">Авторизация загрузки фото: 
                @if($vk['user_token_expires_at'] && (int) $vk['user_token_expires_at'] <= now()->timestamp + 120)
                    истекла
                @elseif($vk['user_token_expires_at'])
                    действительна до {{ \Illuminate\Support\Carbon::createFromTimestamp((int) $vk['user_token_expires_at'], config('app.timezone'))->format('d.m.Y H:i T') }}
                @elseif($vk['user_token'])
                    срок неизвестен
                @else
                    не подключена
                @endif
            </p>
            <p class="text-sm">Ожидающие авторизацию публикации с обложкой автоматически возобновляются после её восстановления (проверка каждые 5 минут). Записи с неизвестным результатом отправки не повторяются.</p>
            <input type="hidden" name="enabled" value="0">
            <label style="display:flex;align-items:center;gap:8px">
                <input class="fi-checkbox-input" type="checkbox" name="enabled" value="1" @checked($vk['enabled']) @disabled(config('app.env') !== 'production')>
                Разрешить публикацию материалов
            </label>
            <p class="text-sm">Пустые токены не заменяют сохранённые. Проверка подключения не создаёт записей на стене.</p>
            <label style="display:grid;gap:6px">
                Пароль суперадминистратора
                <x-filament::input.wrapper>
                    <x-filament::input type="password" name="password" autocomplete="current-password" required />
                </x-filament::input.wrapper>
            </label>
            <div><x-filament::button type="submit">Сохранить</x-filament::button></div>
        </form>
        @if($vk['mini_app_secret'] && $vk['mini_app_id'] && $vk['group_id'] && $vk['community_token'] && config('app.env') === 'production')
            <form method="POST" action="{{ route('backoffice.vk-publisher.authorize') }}" target="_blank" rel="noopener noreferrer" class="mt-4">
                @csrf
                <x-filament::button type="submit" color="gray">Авторизоваться во ВКонтакте</x-filament::button>
            </form>
            <form method="POST" action="{{ route('backoffice.vk-publisher.complete') }}" class="mt-4" style="display:grid;gap:12px;max-width:44rem" autocomplete="off">
                @csrf
                <p class="text-sm">После согласия ВК откроет свою страницу blank.html. Вернитесь в эту вкладку. Для завершения своей интеграции MSKBA используйте только адрес ответа с code и state, без access_token. Не отправляйте его в чаты и сторонние сервисы.</p>
                <label style="display:grid;gap:6px">
                    Ответ авторизации ВКонтакте
                    <x-filament::input.wrapper>
                        <x-filament::input type="password" name="authorization_response" autocomplete="off" required maxlength="8192" />
                    </x-filament::input.wrapper>
                </label>
                <div><x-filament::button type="submit" color="gray">Завершить авторизацию</x-filament::button></div>
            </form>
        @endif
        <form method="POST" action="{{ route('backoffice.social-integrations.check', 'vk') }}" class="mt-4">
            @csrf
            <x-filament::button type="submit" color="gray">Проверить подключение</x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>
