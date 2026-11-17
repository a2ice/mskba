@extends('theme::layouts.account', ['title' => 'Управление контрактами'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Контракты: '.$entityName,
        'intro' => 'Приглашения, полномочия и управление командой.',
    ])
@endsection

@section('account-content')
    <div class="account-collection account-contracts-workspace" style="display:grid;gap:16px">
        <section class="app-team-panel account-collection__section" aria-labelledby="entity-contract-invite">
            <a class="account-collection__reset" href="{{ route('account.contracts') }}">Мои контракты</a>
            @if(session('status'))
                <p role="status" class="mt-3">{{ session('status') }}</p>
            @endif
            <h2 id="entity-contract-invite" style="margin-block:16px">Пригласить пользователя</h2>
            <form class="account-collection__filters" method="POST" action="{{ route('account.contracts.invite', $membership->id) }}" style="display:grid;gap:16px">
                @csrf
                <label class="app-team-field">
                    <span>Логин пользователя</span>
                    <input type="text" name="username" value="{{ old('username') }}" maxlength="150" autocomplete="off" required>
                </label>
                <label class="app-team-field">
                    <span>Название контракта</span>
                    <input type="text" name="name" value="{{ old('name') }}" maxlength="150" minlength="2" placeholder="Например, менеджер сезона" required>
                </label>
                <label class="app-team-field">
                    <span>Действует до (необязательно)</span>
                    <input type="datetime-local" name="expires_at" value="{{ old('expires_at') }}">
                    <small>Без даты контракт бессрочный, если ваши полномочия тоже бессрочны.</small>
                </label>
                <label class="app-team-field">
                    <span>Комментарий (необязательно)</span>
                    <textarea name="notes" maxlength="2000" rows="3">{{ old('notes') }}</textarea>
                </label>
                <fieldset style="display:grid;gap:12px">
                    <legend>Полномочия</legend>
                    @foreach($permissions as $permission)
                        <label class="app-team-checkbox" style="display:flex;align-items:center;gap:10px">
                            <input type="checkbox" name="permissions[]" value="{{ $permission->value }}" @checked(in_array($permission->value, (array) old('permissions', []), true))>
                            <span>{{ $permission->label() }}</span>
                        </label>
                    @endforeach
                </fieldset>
                @if($errors->any())
                    <div class="account-collection__error" role="alert">
                        @foreach($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif
                <div class="app-team-panel__actions">
                    <button class="account-collection__action account-collection__action--primary" type="submit">Отправить приглашение</button>
                </div>
            </form>
        </section>

        @if($canTransfer)
            <section class="app-team-panel account-collection__section" aria-labelledby="entity-contract-transfer">
                <h2 id="entity-contract-transfer">Передача владения</h2>
                <form method="POST" action="{{ route('account.contracts.transfer', $membership->id) }}" style="display:grid;gap:16px;margin-top:16px">
                    @csrf
                    <label class="app-team-field">
                        <span>Логин нового владельца</span>
                        <input type="text" name="username" maxlength="150" autocomplete="off" required>
                    </label>
                    <p>Ваши полномочия сохраняются до принятия предложения новым владельцем.</p>
                    <div><button class="account-collection__action account-collection__action--primary" type="submit">Предложить передачу</button></div>
                </form>
            </section>
        @endif

        <section class="app-team-panel account-collection__section" aria-labelledby="entity-contract-memberships">
            <h2 id="entity-contract-memberships">Назначенные контракты</h2>
            <div class="account-collection__cards" style="display:grid;gap:16px;margin-top:16px">
                @forelse($memberships as $record)
                    <article class="account-collection__card" style="display:grid;gap:12px">
                        <div class="account-collection__card-head" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                            <div>
                                <h3>{{ $record->contract?->name ?? 'Контракт' }}</h3>
                                <p>{{ $record->user?->username ?? 'Пользователь' }}</p>
                            </div>
                            <span class="badge account-collection__status">
                                {{ match ($record->invitation_status?->value) {
                                    'accepted' => 'Принят',
                                    'pending' => 'Ожидает подтверждения',
                                    'declined' => 'Отклонён',
                                    'revoked' => 'Отозван',
                                    default => '—',
                                } }}
                            </span>
                        </div>
                        @if($record->contract?->permissions?->isNotEmpty())
                            <p>{{ $record->contract->permissions->map(fn ($p) => collect($permissions)->firstWhere('value', $p->permission)?->label())->filter()->join(', ') }}</p>
                        @endif
                        @if(! in_array($record->access_level, ['owner', 'primary_manager'], true)
                            && $record->contract?->comment === \App\Modules\Contract\Application\Services\EntityContractManager::COMMENT_MARKER
                            && $record->contract?->status === \App\Modules\Contract\Domain\Enums\ContractStatusEnum::ACTIVE)
                            <form method="POST" action="{{ route('account.contracts.revoke', [$membership->id, $record->id]) }}">
                                @csrf
                                @method('DELETE')
                                <button class="account-collection__action" type="submit">Отозвать</button>
                            </form>
                        @endif
                    </article>
                @empty
                    <p>Назначенных контрактов пока нет.</p>
                @endforelse
            </div>
            <div class="app-team-pagination">{{ $memberships->links() }}</div>
        </section>
    </div>
@endsection
