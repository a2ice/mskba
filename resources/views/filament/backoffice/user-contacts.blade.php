@php
    use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
    use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
    use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;

    $allowed = $editedUser !== null && BackofficeUserResource::canEdit($editedUser);
    $canManageContacts = $allowed && app(\App\Modules\Contact\Application\UseCases\AdminManageUserContactsHandler::class)->canManage(auth('web')->user(), $editedUser);
    $contacts = $allowed ? $editedUser->identityContactsQuery()
        ->withTrashed()
        ->with(['verifications' => fn ($query) => $query
            ->select(['id', 'contact_id', 'channel', 'status', 'attempts_count', 'max_attempts', 'created_at', 'verified_at', 'failed_at', 'expires_at'])
            ->latest('id')])
        ->orderByDesc('is_primary')->orderBy('id')->get() : collect();
    $identityIds = $allowed ? $editedUser->identityIds() : [];
    $telegramAccounts = $allowed ? \App\Modules\Telegram\Domain\Models\TelegramAccount::query()
        ->whereIn('user_id', $identityIds)->get(['user_id', 'telegram_user_id', 'username', 'created_at', 'last_auth_at']) : collect();
    $vkAccounts = $allowed ? \App\Modules\Vk\Domain\Models\VkAccount::query()
        ->whereIn('user_id', $identityIds)->get(['user_id', 'vk_user_id', 'first_name', 'last_name', 'created_at', 'last_auth_at']) : collect();
    [$deletedContacts, $activeContacts] = $contacts->partition(fn ($contact) => $contact->trashed());
    $date = static fn ($value): string => $value?->format('d.m.Y H:i') ?? '—';
@endphp

@if($allowed)
    <x-filament::section heading="Контактные данные">
        @if($canManageContacts)
            <div class="mb-4 flex justify-end">
                <x-filament::button type="button" icon="heroicon-o-plus" wire:click="mountAction('addUserContact')">Добавить контакт</x-filament::button>
            </div>
        @endif
        <div class="space-y-4">
            @foreach([$activeContacts, $deletedContacts] as $groupIndex => $group)
                @if($groupIndex === 1)
                    @if($group->isEmpty())
                        @continue
                    @endif
                    <details>
                        <summary class="cursor-pointer text-primary-600">Удалённые контакты ({{ $group->count() }})</summary>
                        <div class="mt-3 space-y-4">
                @endif

                @foreach($group as $contact)
                    @php
                        $currentVerification = $contact->verified_at && !$contact->trashed()
                            ? $contact->verifications->first(fn ($verification) =>
                                $verification->status === ContactVerificationStatusEnum::CONFIRMED
                                && $verification->verified_at !== null
                                && abs($verification->verified_at->getTimestamp() - $contact->verified_at->getTimestamp()) <= 2)
                            : null;
                        $socialSource = match ($contact->meta['source'] ?? null) {
                            'telegram_mini_app' => 'Telegram Mini App',
                            'telegram_web', 'account_telegram_link' => 'Telegram — авторизация',
                            'telegram_chat' => 'Telegram — бот',
                            'vk_id', 'vk_id_link', 'vk_id_auth' => 'VK ID',
                            'test_provider_emulation' => 'Тестовая эмуляция',
                            default => null,
                        };
                    @endphp
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700" wire:key="backoffice-contact-{{ $contact->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <div class="text-sm text-gray-500">{{ $contact->type->label() }} @if($contact->label) — {{ $contact->label }} @endif</div>
                                <div class="break-all font-semibold">{{ $contact->displayValue() }}</div>
                                @if($contact->displayValue() !== $contact->value)
                                    <div class="text-sm text-gray-500">ID: {{ $contact->value }}</div>
                                @endif
                            </div>
                            <x-filament::badge :color="$contact->trashed() ? 'gray' : ($contact->verified_at ? 'success' : 'warning')">
                                {{ $contact->trashed() ? 'Удалён' : ($contact->verified_at ? 'Подтверждён' : 'Не подтверждён') }}
                            </x-filament::badge>
                        </div>
                        <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div><dt class="text-sm text-gray-500">Основной контакт</dt><dd>{{ $contact->is_primary ? 'Да' : 'Нет' }}</dd></div>
                            <div><dt class="text-sm text-gray-500">Публичный</dt><dd>{{ $contact->is_public ? 'Да' : 'Нет' }}</dd></div>
                            <div><dt class="text-sm text-gray-500">Добавлен</dt><dd>{{ $date($contact->created_at) }}</dd></div>
                            <div><dt class="text-sm text-gray-500">Подтверждён</dt><dd>{{ $contact->trashed() ? '—' : $date($contact->verified_at) }}</dd></div>
                            <div><dt class="text-sm text-gray-500">Способ подтверждения</dt><dd>{{ $currentVerification?->channel->label() ?? ($contact->verified_at && !$contact->trashed() ? 'Не зафиксирован' : '—') }}</dd></div>
                            <div><dt class="text-sm text-gray-500">Попыток ввода кода при подтверждении</dt><dd>{{ $currentVerification?->attempts_count ?? '—' }}</dd></div>
                            @if($socialSource)
                                <div><dt class="text-sm text-gray-500">Последний сохранённый источник привязки</dt><dd>{{ $socialSource }}</dd></div>
                            @endif
                            @if($contact->trashed())
                                <div><dt class="text-sm text-gray-500">Удалён</dt><dd>{{ $date($contact->deleted_at) }}</dd></div>
                            @endif
                            @if((int) $contact->contactable_id !== (int) $editedUser->id)
                                <div><dt class="text-sm text-gray-500">Связанный аккаунт</dt><dd>#{{ $contact->contactable_id }}</dd></div>
                            @endif
                        </dl>
                        @if($canManageContacts && !$contact->trashed())
                            <div class="mt-3 flex flex-wrap gap-2">
                                @if($contact->type === ContactTypeEnum::EMAIL && !$contact->hasBeenVerified())
                                    <x-filament::button size="sm" type="button" color="gray" wire:click="mountAction('sendUserContactCode', { contact_id: {{ $contact->id }} })">Отправить код</x-filament::button>
                                    <x-filament::button size="sm" type="button" wire:click="mountAction('confirmUserContactCode', { contact_id: {{ $contact->id }} })">Подтвердить кодом</x-filament::button>
                                @elseif($contact->type === ContactTypeEnum::PHONE && !$contact->hasBeenVerified())
                                    <span class="text-sm text-gray-500">SMS-подтверждение пока недоступно</span>
                                @endif
                                @if($contact->hasBeenVerified() && !$contact->is_primary)
                                    <x-filament::button size="sm" type="button" color="gray" wire:click="mountAction('setUserPrimaryContact', { contact_id: {{ $contact->id }} })">Сделать основным</x-filament::button>
                                @endif
                            </div>
                        @endif
                        @if($contact->verifications->isNotEmpty())
                            <details class="mt-4">
                                <summary class="cursor-pointer text-primary-600">История подтверждений ({{ $contact->verifications->count() }})</summary>
                                <div class="mskba-bo-table mskba-bo-table--cards mt-2">
                                    <table class="mskba-bo-table__table">
                                        <thead><tr><th>Создано</th><th>Канал</th><th>Статус</th><th>Попытки ввода</th><th>Подтверждено</th><th>Ошибка</th><th>Срок действия</th></tr></thead>
                                        <tbody>
                                            @foreach($contact->verifications as $verification)
                                                <tr>
                                                    <td data-label="Создано">{{ $date($verification->created_at) }}</td>
                                                    <td data-label="Канал">{{ $verification->channel->label() }}</td>
                                                    <td data-label="Статус">{{ $verification->status->label() }}</td>
                                                    <td data-label="Попытки ввода">{{ $verification->attempts_count }} / {{ $verification->max_attempts }}</td>
                                                    <td data-label="Подтверждено">{{ $date($verification->verified_at) }}</td>
                                                    <td data-label="Ошибка">{{ $date($verification->failed_at) }}</td>
                                                    <td data-label="Срок действия">{{ $date($verification->expires_at) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        @else
                            <p class="mt-3 text-sm text-gray-500">История подтверждений отсутствует.</p>
                        @endif
                    </div>
                @endforeach

                @if($groupIndex === 0)
                    @foreach([ContactTypeEnum::EMAIL, ContactTypeEnum::PHONE, ContactTypeEnum::TELEGRAM, ContactTypeEnum::VK] as $type)
                        @if(!$group->contains('type', $type)
                            && !($type === ContactTypeEnum::TELEGRAM && $telegramAccounts->isNotEmpty())
                            && !($type === ContactTypeEnum::VK && $vkAccounts->isNotEmpty()))
                            <div class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                <span>{{ $type->label() }}</span>
                                <span class="text-sm text-gray-500">Не добавлен</span>
                            </div>
                        @endif
                    @endforeach
                @else
                        </div>
                    </details>
                @endif
            @endforeach
        </div>
    </x-filament::section>
    @if($telegramAccounts->isNotEmpty() || $vkAccounts->isNotEmpty())
        <x-filament::section heading="Привязанные способы входа">
            <div class="space-y-3">
                @foreach($telegramAccounts as $account)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <strong>Telegram · {{ $account->username ? '@'.$account->username : 'ID '.$account->telegram_user_id }}</strong>
                        <dl class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div><dt class="text-sm text-gray-500">ID Telegram</dt><dd>{{ $account->telegram_user_id }}</dd></div>
                            <div><dt class="text-sm text-gray-500">ID аккаунта MSKBA</dt><dd>{{ $account->user_id }}</dd></div>
                            <div><dt class="text-sm text-gray-500">Привязка создана</dt><dd>{{ $date($account->created_at) }}</dd></div>
                            <div><dt class="text-sm text-gray-500">Последняя авторизация</dt><dd>{{ $date($account->last_auth_at) }}</dd></div>
                        </dl>
                    </div>
                @endforeach
                @foreach($vkAccounts as $account)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <strong>VK · {{ trim($account->first_name.' '.$account->last_name) ?: 'ID '.$account->vk_user_id }}</strong>
                        <dl class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div><dt class="text-sm text-gray-500">ID VK</dt><dd>{{ $account->vk_user_id }}</dd></div>
                            <div><dt class="text-sm text-gray-500">ID аккаунта MSKBA</dt><dd>{{ $account->user_id }}</dd></div>
                            <div><dt class="text-sm text-gray-500">Привязка создана</dt><dd>{{ $date($account->created_at) }}</dd></div>
                            <div><dt class="text-sm text-gray-500">Последняя авторизация</dt><dd>{{ $date($account->last_auth_at) }}</dd></div>
                        </dl>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
@endif
