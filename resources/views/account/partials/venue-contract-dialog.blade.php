
<dialog class="account-contracts-dialog" id="venue-contract-{{ $venue->id }}" aria-labelledby="venue-contract-title-{{ $venue->id }}" data-contract-dialog>
    <div class="account-contracts-dialog__header">
        <div>
            <p class="account-contracts-workspace__eyebrow">Площадка</p>
            <h2 id="venue-contract-title-{{ $venue->id }}">Контракты: {{ $venue->name }}</h2>
        </div>
        <button type="button" data-contract-dialog-close aria-label="Закрыть информацию о контракте" class="account-contracts-dialog__close">×</button>
    </div>
    <div class="account-contracts-dialog__body">
        @forelse($card['contracts'] as $contract)
            <section class="account-contracts-dialog__contract" aria-label="Контракт №{{ $contract['number'] ?? $contract['id'] }}">
                <div class="account-contracts-workspace__card-head">
                    <h3>№ {{ $contract['number'] ?? $contract['id'] }}</h3>
                    <span class="account-contracts-workspace__status {{ $contract['effective'] ? 'is-active' : '' }}">{{ $contract['status'] }}{{ $contract['effective'] ? ' · действует' : ' · не действует' }}</span>
                </div>
                <dl class="account-contracts-dialog__details">
                    <div><dt>Область</dt><dd>{{ $contract['scope'] }}</dd></div>
                    <div><dt>Роль</dt><dd>{{ $contract['role'] }}</dd></div>
                    <div><dt>Кем назначен</dt><dd>{{ $contract['assigned_by'] }}</dd></div>
                    <div><dt>Дата назначения</dt><dd>{{ $contract['assigned_at'] ?? 'Не указана' }}</dd></div>
                    <div><dt>Создан</dt><dd>{{ $contract['created_at'] ?? '—' }}</dd></div>
                    <div><dt>Действует с</dt><dd>{{ $contract['starts_at'] ?? 'Не ограничено' }}</dd></div>
                    <div><dt>Действует до</dt><dd>{{ $contract['expires_at'] ?? 'Бессрочно' }}</dd></div>
                </dl>
                <h4>Все разрешения площадки</h4>
                <ul class="account-contracts-dialog__permissions">
                    @foreach($contract['permissions'] as $permission)
                        <li class="{{ $permission['active'] ? 'is-granted' : 'is-disabled' }}">
                            <span aria-label="{{ $permission['active'] ? 'Разрешено' : 'Нет доступа' }}" class="account-contracts-dialog__mark">{{ $permission['active'] ? '✓' : '—' }}</span>
                            <span>{{ $permission['label'] }} <small>{{ $permission['code'] }}</small></span>
                        </li>
                    @endforeach
                </ul>
                @if($contract['scope'] !== 'Площадка')
                    <p class="account-contracts-dialog__note">Разрешения этого контракта относятся только к указанной зоне, а не ко всей площадке.</p>
                @endif
                <a href="{{ route('account.contracts.show', $contract['id']) }}" class="account-contracts-workspace__text-link">Полная карточка контракта</a>
            </section>
        @empty
            <p>У этой площадки пока нет назначенного контракта. Она доступна вам как создателю в рамках временных прав, пока не оформлены договорные отношения.</p>
            <p class="account-contracts-dialog__note">Формальные разрешения будут отображаться после назначения контракта.</p>
        @endforelse
    </div>
    <div class="account-contracts-dialog__footer">
        <button type="button" class="account-contracts-workspace__secondary" data-contract-dialog-close>Закрыть</button>
    </div>
</dialog>
