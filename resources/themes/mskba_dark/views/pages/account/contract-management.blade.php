@extends('theme::layouts.section-sidebar', [
    'title' => 'Управление контрактами',
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => 'Контракты: '.$entityName,
    'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])
@section('section-content')
<div class="account-contracts-workspace d-grid gap-4">
    <div class="account-contracts-workspace__card">
        <a class="account-contracts-workspace__text-link" href="{{ route('account.contracts') }}">Мои контракты</a>
        @if(session('status')) <p role="status" class="mt-3">{{ session('status') }}</p> @endif
        <h2 class="mt-3 mb-3">Пригласить пользователя</h2>
        <form method="POST" action="{{ route('account.contracts.invite', $membership->id) }}" class="d-grid gap-3">
            @csrf
            <label class="d-grid gap-2">Логин пользователя
                <input class="form-control" type="text" name="username" value="{{ old('username') }}" required maxlength="150" autocomplete="off">
            </label>
            <label class="d-grid gap-2">Название контракта
                <input class="form-control" type="text" name="name" value="{{ old('name') }}" required minlength="2" maxlength="150" placeholder="Например, менеджер сезона">
            </label>
            <fieldset class="d-grid gap-3">
                <legend class="mb-2">Полномочия</legend>
                @foreach($permissions as $permission)
                    <label class="d-flex align-items-center gap-2">
                        <input type="checkbox" name="permissions[]" value="{{ $permission->value }}" @checked(in_array($permission->value, old('permissions', []), true))>
                        <span>{{ $permission->label() }}</span>
                    </label>
                @endforeach
            </fieldset>
            @if($errors->any())
                <div role="alert" class="text-danger">
                    @foreach($errors->all() as $error) <p class="mb-1">{{ $error }}</p> @endforeach
                </div>
            @endif
            <div><button type="submit" class="account-contracts-workspace__primary">Отправить приглашение</button></div>
        </form>
    </div>
    <div class="account-contracts-workspace__card">
        <h2 class="mb-3">Назначенные контракты</h2>
        <div class="d-grid gap-3">
            @forelse($memberships as $record)
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 pb-3 border-bottom">
                    <div>
                        <strong>{{ $record->contract?->name ?? 'Контракт' }}</strong>
                        <p class="mb-1">{{ $record->user?->username ?? 'Пользователь' }}</p>
                        <small>{{ match ($record->invitation_status?->value) { 'accepted' => 'Принят', 'pending' => 'Ожидает подтверждения', 'declined' => 'Отклонён', 'revoked' => 'Отозван', default => '—' } }}</small>
                        <p class="mb-0">{{ $record->contract?->permissions?->map(fn ($p) => collect($permissions)->firstWhere('value', $p->permission)?->label())->filter()->join(', ') }}</p>
                    </div>
                    @if($record->access_level !== 'owner' && $record->contract?->status === \App\Modules\Contract\Domain\Enums\ContractStatusEnum::ACTIVE)
                        <form method="POST" action="{{ route('account.contracts.revoke', [$membership->id, $record->id]) }}">
                            @csrf @method('DELETE')
                            <button class="account-contracts-workspace__secondary" type="submit">Отозвать</button>
                        </form>
                    @endif
                </div>
            @empty
                <p>Назначенных контрактов пока нет.</p>
            @endforelse
        </div>
        {{ $memberships->links() }}
    </div>
</div>
@endsection
