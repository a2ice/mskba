@php
    /** @var \App\Modules\Identity\Domain\Models\User|null $editedUser */
    $user = $editedUser?->canonical();
    $user?->loadMissing(['profile', 'telegramAccount', 'vkAccount']);
@endphp

<x-filament::section heading="Общая информация" description="Источник — канонический аккаунт. Здесь только факты регистрации и состояния.">
    @if($user)
        <dl class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div><dt class="text-sm text-gray-500">ID</dt><dd class="font-medium">{{ $user->id }}</dd></div>
            <div><dt class="text-sm text-gray-500">Логин</dt><dd class="font-medium">{{ $user->username }}</dd></div>
            <div><dt class="text-sm text-gray-500">Статус</dt><dd class="font-medium">{{ $user->status->label() }}</dd></div>
            <div><dt class="text-sm text-gray-500">Зарегистрирован</dt><dd>{{ $user->created_at?->format('d.m.Y H:i') ?? '—' }}</dd></div>
            <div><dt class="text-sm text-gray-500">Канал регистрации</dt><dd>{{ $user->registration_channel?->value ?? '—' }}</dd></div>
            <div><dt class="text-sm text-gray-500">Первый вход</dt><dd>{{ $user->first_logged_in_at?->format('d.m.Y H:i') ?? '—' }}</dd></div>
            <div><dt class="text-sm text-gray-500">Системная роль</dt><dd>{{ $user->system_role->label() }}</dd></div>
            <div><dt class="text-sm text-gray-500">Связанные идентификаторы</dt><dd>{{ count($user->identityIds()) }}</dd></div>
            <div><dt class="text-sm text-gray-500">Telegram</dt><dd>{{ $user->telegramAccount ? 'Подключён' : 'Не подключён' }}</dd></div>
            <div><dt class="text-sm text-gray-500">VK</dt><dd>{{ $user->vkAccount ? 'Подключён' : 'Не подключён' }}</dd></div>
        </dl>
    @else
        <p>Пользователь не выбран.</p>
    @endif
</x-filament::section>