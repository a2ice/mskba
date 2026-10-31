
<div class="account-contracts-workspace">
    <nav class="account-contracts-workspace__tabs" aria-label="Разделы моих контрактов">
        <a class="is-current" href="{{ route('account.contracts') }}" aria-current="page">Все контракты</a>
        <a href="{{ route('account.contracts.venues') }}">Площадки</a>
    </nav>
    <form class="account-contracts-workspace__filters" method="GET" action="{{ route('account.contracts') }}" aria-label="Фильтры контрактов">
        <label>Тип сущности
            <span class="select-control">
                <select name="type">
                <option value="">Все типы</option>
                @foreach($contractTypes as $type)
                    <option value="{{ $type->value }}" @selected(($filters['type'] ?? '') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
                @include('account.partials.native-select-chevron')
            </span>
        </label>
        <label>Статус
            <span class="select-control">
                <select name="status">
                <option value="">Все статусы</option>
                @foreach($contractStatuses as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
                @include('account.partials.native-select-chevron')
            </span>
        </label>
        <button type="submit" class="account-contracts-workspace__primary">Найти</button>
        <a class="account-contracts-workspace__reset" href="{{ route('account.contracts') }}">Сбросить</a>
    </form>
    @if(session('status')) <p role="status" class="mb-3">{{ session('status') }}</p> @endif
    <p class="account-contracts-workspace__count" role="status">Найдено контрактов: {{ $contracts->total() }}</p>
    <div class="account-contracts-workspace__cards">
        @forelse($contracts as $record)
            @php($contract = $record['contract'])
            <article class="account-contracts-workspace__card">
                <div class="account-contracts-workspace__card-head">
                    <div>
                        <p class="account-contracts-workspace__eyebrow">{{ $contract->membership?->scope_type?->label() ?? 'Контракт' }}</p>
                        <h2>{{ $record['scopes'][0]['name'] ?? ($contract->name ?? 'Контракт #'.$contract->id) }}</h2>
                    </div>
                    <span class="account-contracts-workspace__status {{ $contract->status->value === 'active' ? 'is-active' : '' }}">{{ $contract->status->label() }}</span>
                </div>
                <p>Контракт: <strong>{{ $contract->name }}</strong></p>
                <p>Роль: <strong>{{ $record['access_label'] ?? '—' }}</strong></p>
                @if($contract->membership?->invitation_status === \App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum::PENDING)
                    <p class="mb-3">Ожидает вашего ответа</p>
                    <div class="account-contracts-workspace__actions mb-3">
                        <form method="POST" action="{{ route('account.contracts.respond', $contract->membership->id) }}">
                            @csrf<input type="hidden" name="decision" value="accept">
                            <button class="account-contracts-workspace__primary" type="submit">Принять</button>
                        </form>
                        <form method="POST" action="{{ route('account.contracts.respond', $contract->membership->id) }}">
                            @csrf<input type="hidden" name="decision" value="decline">
                            <button class="account-contracts-workspace__secondary" type="submit">Отклонить</button>
                        </form>
                    </div>
                @endif
                <div class="account-contracts-workspace__meta">
                    <span>№ {{ $contract->number ?? $contract->id }}</span>
                    <span>С {{ $contract->starts_at?->format('d.m.Y') ?? 'даты назначения' }}</span>
                    <span>До {{ $contract->expires_at?->format('d.m.Y') ?? 'бессрочно' }}</span>
                </div>
                <div class="account-contracts-workspace__actions">
                    <a class="account-contracts-workspace__secondary" href="{{ route('account.contracts.show', $contract->id) }}">Подробнее о контракте</a>
                    @if($record['can_manage'] ?? false)
                        <a class="account-contracts-workspace__secondary" href="{{ route('account.contracts.manage', $contract->membership->id) }}">Управлять контрактами</a>
                    @endif
                    @foreach($record['scopes'] as $scope)
                        @if($scope['url'])
                            <a class="account-contracts-workspace__text-link" href="{{ $scope['url'] }}">Открыть {{ mb_strtolower($scope['type_label']) }}</a>
                        @endif
                    @endforeach
                </div>
            </article>
        @empty
            <div class="account-contracts-workspace__empty">Контрактов с такими параметрами нет.</div>
        @endforelse
    </div>
    @if($contracts->hasPages())
        <div class="account-contracts-workspace__pagination">{{ $contracts->links() }}</div>
    @endif
</div>
