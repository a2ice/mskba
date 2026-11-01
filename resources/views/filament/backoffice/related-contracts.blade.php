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
    <div class="mskba-contract-panel">
        <div class="mskba-contract-panel__toolbar">
            <p class="mskba-contract-panel__count">Всего контрактов: {{ $total }}</p>
            <a class="mskba-contract-panel__link"
               href="{{ ContractResource::getUrl('index') }}">Все контракты и фильтры</a>
        </div>
        <div class="mskba-contract-table-wrap">
            <table class="mskba-contract-table">
                <thead>
                    <tr>
                        <th scope="col">Контракт</th>
                        <th scope="col">{{ $isUser ? 'Сущность' : 'Пользователь' }}</th>
                        <th scope="col">Статус</th>
                        <th scope="col">Срок</th>
                        <th scope="col">Действие</th>
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
                    <tr>
                        <td data-label="Контракт">
                            <span class="mskba-contract-table__name">{{ $contract->name }}</span>
                            <span class="mskba-contract-table__detail">{{ $contract->permissions->count() }} разрешений</span>
                        </td>
                        <td data-label="{{ $isUser ? 'Сущность' : 'Пользователь' }}">
                            {{ $isUser ? ($membership->scope_type?->label().' #'.$membership->scope_id)
                                : (($membership->user?->username ?? '—').($membership->scope_type?->value === 'venue_court' ? ' · Зал / зона #'.$membership->scope_id : '')) }}
                        </td>
                        <td data-label="Статус"><span class="mskba-contract-table__status" data-status="{{ $status === 'Активен' ? 'active' : 'inactive' }}">{{ $status }}</span></td>
                        <td data-label="Срок">{{ $contract->expires_at?->format('d.m.Y H:i') ?? 'Бессрочно' }}</td>
                        <td data-label="Действие">
                            @if($mayEdit)
                                <a class="mskba-contract-table__action"
                                   href="{{ ContractResource::getUrl('edit', ['record' => $contract]) }}">Изменить</a>
                            @else
                                <span class="mskba-contract-table__detail">Только просмотр</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="mskba-contract-table__empty" colspan="5">Контрактов пока нет.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($total > 40)
            <p class="mskba-contract-panel__hint">Показаны последние 40 контрактов. Полный список доступен в общем разделе.</p>
        @endif
    </div>
</x-filament::section>
