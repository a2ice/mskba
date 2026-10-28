
<div class="account-contracts-workspace">
    <nav class="account-contracts-workspace__tabs" aria-label="Разделы моих контрактов">
        <a href="{{ route('account.contracts') }}">Все контракты</a>
        <a class="is-current" href="{{ route('account.contracts.venues') }}" aria-current="page">Площадки</a>
    </nav>
    <form class="account-contracts-workspace__filters account-contracts-workspace__filters--venues" method="GET" action="{{ route('account.contracts.venues') }}" aria-label="Поиск своих площадок">
        <label>Площадка
            <input name="q" type="search" maxlength="120" placeholder="Название или адрес" value="{{ $filters['q'] ?? '' }}">
        </label>
        <label>Статус контракта
            <select name="status">
                <option value="">Все статусы</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>Действующий</option>
                <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Недействующий</option>
                <option value="none" @selected(($filters['status'] ?? '') === 'none')>Ещё без контракта</option>
            </select>
        </label>
        <label>Специализация / тип площадки
            <select name="specialization">
                <option value="">Все специализации</option>
                @foreach($specializations as $specialization)
                    <option value="{{ $specialization->value }}" @selected(($filters['specialization'] ?? '') === $specialization->value)>{{ $specialization->label() }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="account-contracts-workspace__primary">Найти</button>
        <a class="account-contracts-workspace__reset" href="{{ route('account.contracts.venues') }}">Сбросить</a>
    </form>
    <p class="account-contracts-workspace__count" role="status">Найдено площадок: {{ $venues->total() }}</p>
    <div class="account-contracts-workspace__cards">
        @forelse($venues as $card)
            @php($venue = $card['venue'])
            @php($legacy = $card['legacy'])
            <article class="account-contracts-workspace__card">
                <div class="account-contracts-workspace__card-head">
                    <div>
                        <p class="account-contracts-workspace__eyebrow">{{ $venue->type->label() }}</p>
                        <h2><a href="{{ route('account.venues.show', $venue->routeIdentifier()) }}">{{ $venue->name }}</a></h2>
                    </div>
                    <span class="account-contracts-workspace__status {{ $card['effective'] ? 'is-active' : '' }}">
                        {{ $card['effective'] ? 'Контракт действует' : ($card['contracts'] !== [] ? 'Контракт не действует' : 'Без контракта') }}
                    </span>
                </div>
                @if($venue->raw_address)
                    <p class="account-contracts-workspace__address">{{ $venue->raw_address }}</p>
                @endif
                @if($venue->short_description)
                    <p>{{ $venue->short_description }}</p>
                @endif
                <div class="account-contracts-workspace__meta">
                    <span>Статус площадки: {{ $venue->status->label() }}</span>
                    <span>Контрактов: {{ count($card['contracts']) }}</span>
                    @if($card['contracts'] !== [])
                        <span>Роль: {{ $card['contracts'][0]['role'] }}</span>
                    @endif
                </div>
                <div class="account-contracts-workspace__actions">
                    <a class="account-contracts-workspace__primary" href="{{ route('account.venues.show', $venue->routeIdentifier()) }}">Открыть</a>
                    <button type="button" class="account-contracts-workspace__secondary" data-contract-dialog-open="venue-contract-{{ $venue->id }}">Контракт</button>
                    @if($legacy?->canEdit)
                        <a class="account-contracts-workspace__secondary" href="{{ route('account.venues.edit', $venue->routeIdentifier()) }}">Редактировать</a>
                    @endif
                    @if($legacy?->canEditSchedule)
                        <a class="account-contracts-workspace__secondary" href="{{ route('account.venues.schedule.edit', $venue->routeIdentifier()) }}">Расписание</a>
                    @endif
                    @if($card['manage_offers'])
                        <a class="account-contracts-workspace__secondary" href="{{ route('account.venues.group-offers.index', ['venue' => $venue->id]) }}">Промокоды</a>
                    @endif
                    @if($legacy?->canDecideBookingRequests)
                        <a class="account-contracts-workspace__secondary" href="{{ route('account.venue-bookings.inbox', ['venue_id' => $venue->id]) }}">Заявки</a>
                    @endif
                </div>
            </article>
            @include('account.partials.venue-contract-dialog', ['card' => $card, 'venue' => $venue])
        @empty
            <div class="account-contracts-workspace__empty">Площадок с такими параметрами нет.</div>
        @endforelse
    </div>
    @if($venues->hasPages())
        <div class="account-contracts-workspace__pagination">{{ $venues->links() }}</div>
    @endif
</div>
