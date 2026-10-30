@php
    /** @var \App\Modules\Identity\Domain\Models\User $editedUser */
    $editedUser = $this->getRecord()->canonical();
    $actor = auth('web')->user();
    $overview = app(\App\Modules\Finance\Application\Queries\GetAccountWalletOverview::class)
        ->handle($editedUser, 100);
    $funding = app(\App\Modules\Finance\Application\Services\BackofficeUserWalletFunding::class);
    $canFund = $actor !== null && $funding->canFund($actor, $editedUser);
    $canGrantSelf = $actor !== null
        && (int) $actor->canonical()->id === (int) $editedUser->id
        && app(\App\Modules\Finance\Application\Services\SuperadminWalletBonusGrant::class)->allowed($actor);
    $money = static fn (int $minor, bool $signed = false): string => (
        $signed && $minor !== 0 ? ($minor > 0 ? '+' : '−') : ($minor < 0 ? '−' : '')
    ).number_format(abs($minor) / 100, 2, ',', ' ').' ₽';
@endphp

<div class="space-y-5" data-backoffice-user-wallet>
    <x-filament::section heading="Баланс пользователя" description="Те же реальные и бонусные средства, что в личном кабинете. Операции учитываются в рублях и копейках.">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(185px,1fr));gap:12px">
            <div style="padding:16px;border:1px solid #dbe3ed;border-radius:12px">
                <div style="font-size:12px;color:#64748b;margin-bottom:6px">Всего доступно</div>
                <strong style="font-size:22px">{{ $money($overview['totalBalanceMinor']) }}</strong>
            </div>
            <div style="padding:16px;border:1px solid #dbe3ed;border-radius:12px">
                <div style="font-size:12px;color:#64748b;margin-bottom:6px">Основной баланс</div>
                <strong style="font-size:22px">{{ $money($overview['realBalanceMinor']) }}</strong>
            </div>
            <div style="padding:16px;border:1px solid #dbe3ed;border-radius:12px">
                <div style="font-size:12px;color:#64748b;margin-bottom:6px">Бонусный баланс</div>
                <strong style="font-size:22px">{{ $money($overview['bonusBalanceMinor']) }}</strong>
            </div>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:16px">
            @if($canGrantSelf)
                <x-filament::button type="button" icon="heroicon-o-plus-circle"
                    wire:click="mountAction('grantOwnWalletBonus')">
                    Пополнить свой бонусный баланс
                </x-filament::button>
            @endif
            @if($canFund)
                <x-filament::button type="button" icon="heroicon-o-arrow-right-circle"
                    wire:click="mountAction('fundUserWalletBonus')">
                    Начислить бонусы пользователю
                </x-filament::button>
            @endif
        </div>
        @if($canFund)
            <p style="font-size:12px;color:#64748b;margin-top:12px">
                Начисление списывается с бонусного баланса выбранного superadmin. Получатель и источник видят одну операцию в своих историях.
                Основной баланс не переводится.
            </p>
        @elseif($canGrantSelf)
            <p style="font-size:12px;color:#64748b;margin-top:12px">
                Для пополнения собственного бонусного баланса требуется текущий пароль superadmin.
                Начисление сохраняется в финансовой истории.
            </p>
        @else
            <p style="font-size:12px;color:#64748b;margin-top:12px">Кошелёк доступен только для просмотра. Для начисления необходимо отдельное право «Пополнять пользовательский баланс».</p>
        @endif
    </x-filament::section>

    <x-filament::section heading="История операций" description="Последние 100 завершённых операций по кошельку пользователя; записи финансового журнала нельзя редактировать или удалять.">
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;text-align:left;min-width:650px">
                <thead>
                    <tr style="border-bottom:1px solid #dbe3ed">
                        <th style="padding:12px">Дата и время</th>
                        <th style="padding:12px">Операция / источник</th>
                        <th style="padding:12px">Основные</th>
                        <th style="padding:12px">Бонусы</th>
                        <th style="padding:12px">Итого</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($overview['operations'] as $operation)
                        <tr wire:key="backoffice-wallet-operation-{{ $operation['id'] }}" style="border-bottom:1px solid #e5e7eb">
                            <td style="padding:12px;white-space:nowrap">
                                {{ $operation['completedAt']?->format('d.m.Y H:i') ?? '—' }}
                                <small style="display:block;color:#64748b">#{{ $operation['id'] }}</small>
                            </td>
                            <td style="padding:12px">
                                <strong>{{ $operation['label'] }}</strong>
                                @if($operation['description'])
                                    <small style="display:block;color:#64748b;margin-top:4px">{{ $operation['description'] }}</small>
                                @endif
                            </td>
                            <td style="padding:12px;white-space:nowrap">{{ $operation['realDeltaMinor'] === 0 ? '—' : $money($operation['realDeltaMinor'], true) }}</td>
                            <td style="padding:12px;white-space:nowrap">{{ $operation['bonusDeltaMinor'] === 0 ? '—' : $money($operation['bonusDeltaMinor'], true) }}</td>
                            <td style="padding:12px;white-space:nowrap;font-weight:600">{{ $money($operation['totalDeltaMinor'], true) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" style="padding:18px;color:#64748b">Операций пока нет.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</div>
