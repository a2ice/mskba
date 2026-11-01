@php
    use App\Filament\Backoffice\Resources\Contracts\ContractResource;
    use App\Modules\Contract\Domain\Models\ContractMembership;
    use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
    use App\Modules\Identity\Domain\Models\User;

    $record = $this->getRecord();
    $isUser = $record instanceof User;
    $query = ContractMembership::query()->with(['contract.permissions', 'user'])
        ->whereHas('contract');
    if ($isUser) {
        $query->whereIn('user_id', $record->canonical()->identityIds());
    } elseif ($contractScope === 'venue') {
        // The venue card covers its own grants and the linked courts/zones.
        $query->where(function ($q) use ($record): void {
            $q->where(function ($m) use ($record): void {
                $m->where('scope_type', 'venue')->where('scope_id', $record->getKey());
            })->orWhere(function ($m) use ($record): void {
                $m->where('scope_type', 'venue_court')->whereIn('scope_id',
                    \App\Modules\Venue\Domain\Models\VenueCourt::query()
                        ->where('venue_id', $record->getKey())->select('id'));
            });
        });
    } else {
        $query->where('scope_type', $contractScope)->where('scope_id', $record->getKey());
    }
    $total = (clone $query)->count();
    $memberships = $query->orderByDesc('id')->limit(40)->get();
    $mayEdit = app(\App\Modules\Contract\Application\Services\BackofficeContractEditor::class)->canManage();
@endphp

<x-filament::section heading="Контракты" description="Все назначения, в том числе ожидающие подтверждения, завершённые и отозванные.">
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-600 dark:text-gray-300">Всего: {{ $total }}</p>
            <a class="text-sm font-semibold text-primary-600 dark:text-primary-400 underline"
               href="{{ ContractResource::getUrl('index') }}">Все контракты и фильтры</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left">
                        <th class="p-3">Контракт</th>
                        <th class="p-3">{{ $isUser ? 'Сущность' : 'Пользователь' }}</th>
                        <th class="p-3">Статус</th>
                        <th class="p-3">Срок</th>
                        <th class="p-3">Действие</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($memberships as $membership)
                    @php
                        $contract = $membership->contract;
                        $status = $contract->status === ContractStatusEnum::INACTIVE ? 'Неактивен'
                            : match ($membership->invitation_status?->value) {
                                'pending' => 'Ожидает подтверждения',
                                'declined' => 'Отклонён',
                                'revoked' => 'Отозван',
                                default => $contract->expires_at?->isPast() ? 'Истёк' : 'Активен',
                            };
                    @endphp
                    <tr class="border-b align-middle">
                        <td class="p-3">
                            <span class="font-semibold">{{ $contract->name }}</span>
                            <span class="block text-xs text-gray-500">{{ $contract->permissions->count() }} разрешений</span>
                        </td>
                        <td class="p-3">
                            {{ $isUser ? ($membership->scope_type?->label().' #'.$membership->scope_id)
                                : (($membership->user?->username ?? '—').($membership->scope_type?->value === 'venue_court' ? ' · Зал / зона #'.$membership->scope_id : '')) }}
                        </td>
                        <td class="p-3">{{ $status }}</td>
                        <td class="p-3 whitespace-nowrap">{{ $contract->expires_at?->format('d.m.Y H:i') ?? 'Бессрочно' }}</td>
                        <td class="p-3">
                            @if($mayEdit)
                                <a class="text-primary-600 dark:text-primary-400 underline"
                                   href="{{ ContractResource::getUrl('edit', ['record' => $contract]) }}">Изменить</a>
                            @else
                                <span class="text-gray-500">Просмотр</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="p-3" colspan="5">Контрактов пока нет.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($total > 40)
            <p class="text-sm text-gray-500">Показаны последние 40. Для полного списка используйте поиск в разделе «Контракты».</p>
        @endif
    </div>
</x-filament::section>
