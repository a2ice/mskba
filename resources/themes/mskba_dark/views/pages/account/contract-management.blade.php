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
            <label class="d-grid gap-2">Шаблон контракта
                <select class="form-select" id="contract-template-select">
                    <option value="">Без шаблона</option>
                    @foreach($contractPresets as $preset)
                        <option value="preset-{{ $loop->index }}" data-template-name="{{ $preset['name'] }}" data-template-permissions="{{ json_encode($preset['permissions']) }}">{{ $preset['name'] }} · системный</option>
                    @endforeach
                    @foreach($contractTemplates as $template)
                        <option value="saved-{{ $template->id }}" data-template-name="{{ $template->name }}" data-template-permissions="{{ json_encode($template->permissions) }}">{{ $template->name }} · {{ $template->shared ? 'общий' : 'мой' }}</option>
                    @endforeach
                </select>
            </label>
            <label class="d-grid gap-2">Название контракта
                <input class="form-control" type="text" name="name" value="{{ old('name') }}" required minlength="2" maxlength="150" placeholder="Например, менеджер сезона">
            </label>
            <label class="d-grid gap-2">Характер контракта
                <select name="legal_nature" class="form-select" id="contract-legal-nature">
                    <option value="technical">Техническое назначение</option>
                    <option value="evidence_backed">Подтверждаемые полномочия</option>
                    <option value="legal_agreement">Юридическое соглашение</option>
                </select>
                <small>Контракты, требующие подтверждения, начинают предоставлять доступ после проверки документов.</small>
            </label>
            <label class="d-grid gap-2">Действует до (необязательно)
                <input class="form-control" type="datetime-local" name="expires_at" value="{{ old('expires_at') }}">
                <small>Без даты контракт бессрочный, если ваши полномочия тоже бессрочны.</small>
            </label>
            <label class="d-grid gap-2">Комментарий (необязательно)
                <textarea class="form-control" name="notes" maxlength="2000" rows="3">{{ old('notes') }}</textarea>
            </label>
            <fieldset class="d-grid gap-3">
                <legend class="mb-2">Полномочия</legend>
                @foreach($permissions as $permission)
                    <label class="d-flex align-items-center gap-2">
                        <input type="checkbox" name="permissions[]" value="{{ $permission->value }}" @checked(in_array($permission->value, (array) old('permissions', []), true))>
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
        <h2 class="mb-3">Шаблоны контрактов</h2>
        <p class="mb-3">Сохраните название и выбранные разрешения, чтобы использовать их при следующих приглашениях.</p>
        <form method="POST" action="{{ route('account.contract-templates.store', $membership->id) }}" class="d-grid gap-3" id="save-contract-template-form">
            @csrf
            <input type="hidden" name="template_name" id="save-contract-template-name">
            <div id="save-contract-template-permissions"></div>
            <label class="d-flex align-items-center gap-2">
                <input type="checkbox" name="shared" value="1">
                Доступен другим управляющим этой сущностью
            </label>
            <div><button class="account-contracts-workspace__secondary" type="button" id="save-contract-template-button">Сохранить текущие параметры как шаблон</button></div>
        </form>

        @if($contractTemplates->isNotEmpty())
            <h3 class="mt-4 mb-3">Сохранённые шаблоны</h3>
            <div class="d-grid gap-3">
                @foreach($contractTemplates as $template)
                    <details class="border rounded-3 p-3">
                        <summary>{{ $template->name }} <small>({{ $template->shared ? 'общий' : 'личный' }})</small></summary>
                        @if(in_array((int) $template->owner_user_id, $templateManager->identityIds(), true))
                            <form class="d-grid gap-3 mt-3" method="POST" action="{{ route('account.contract-templates.update', [$membership->id, $template->id]) }}">
                                @csrf @method('PUT')
                                <label class="d-grid gap-2">Название шаблона
                                    <input type="text" name="template_name" class="form-control" value="{{ $template->name }}" required minlength="2" maxlength="150">
                                </label>
                                <fieldset class="d-grid gap-2">
                                    <legend>Полномочия шаблона</legend>
                                    @foreach($permissions as $permission)
                                        <label class="d-flex align-items-center gap-2">
                                            <input type="checkbox" name="template_permissions[]" value="{{ $permission->value }}" @checked(in_array($permission->value, $template->permissions ?? [], true))>
                                            {{ $permission->label() }}
                                        </label>
                                    @endforeach
                                </fieldset>
                                <label class="d-flex align-items-center gap-2">
                                    <input type="checkbox" name="shared" value="1" @checked($template->shared)> Доступен другим управляющим этой сущностью
                                </label>
                                <div><button class="account-contracts-workspace__primary" type="submit">Сохранить изменения</button></div>
                            </form>
                            <form class="mt-3" method="POST" action="{{ route('account.contract-templates.destroy', [$membership->id, $template->id]) }}">
                                @csrf @method('DELETE')
                                <button class="account-contracts-workspace__secondary" type="submit">Удалить шаблон</button>
                            </form>
                        @else
                            <p class="mt-3 mb-0">{{ collect($template->permissions)->map(fn ($right) => collect($permissions)->firstWhere('value', $right)?->label())->filter()->join(', ') }}</p>
                        @endif
                    </details>
                @endforeach
            </div>
        @endif
    </div>
    @if($canTransfer)
        <div class="account-contracts-workspace__card">
            <h2 class="mb-3">Передача владения</h2>
            <form method="POST" action="{{ route('account.contracts.transfer', $membership->id) }}" class="d-grid gap-3">
                @csrf
                <label class="d-grid gap-2">Логин нового владельца
                    <input class="form-control" type="text" name="username" maxlength="150" required autocomplete="off">
                </label>
                <p>Текущие полномочия сохраняются до принятия приглашения новым владельцем.</p>
                <div><button class="account-contracts-workspace__primary" type="submit">Предложить передачу</button></div>
            </form>
        </div>
    @endif
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
                    @if(! in_array($record->access_level, ['owner', 'primary_manager'], true)
                        && $record->contract?->comment === \App\Modules\Contract\Application\Services\EntityContractManager::COMMENT_MARKER
                        && $record->contract?->status === \App\Modules\Contract\Domain\Enums\ContractStatusEnum::ACTIVE)
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
<script>
document.addEventListener('DOMContentLoaded', () => {
    const source = document.querySelector('#contract-template-select');
    const invitation = source?.closest('form');
    const name = invitation?.querySelector('input[name="name"]');
    const save = document.querySelector('#save-contract-template-button');
    const saveForm = document.querySelector('#save-contract-template-form');
    if (!source || !invitation || !name || !save || !saveForm) return;

    source.addEventListener('change', () => {
        const option = source.selectedOptions[0];
        if (!option?.dataset.templateName) return;
        name.value = option.dataset.templateName;
        let selected = [];
        try { selected = JSON.parse(option.dataset.templatePermissions || '[]'); } catch {}
        invitation.querySelectorAll('input[name="permissions[]"]').forEach((input) => {
            input.checked = selected.includes(input.value);
        });
    });

    save.addEventListener('click', () => {
        const title = name.value.trim();
        const permissions = Array.from(invitation.querySelectorAll('input[name="permissions[]"]:checked')).map((input) => input.value);
        if (title.length < 2 || permissions.length === 0) {
            name.focus();
            return;
        }
        document.querySelector('#save-contract-template-name').value = title;
        const container = document.querySelector('#save-contract-template-permissions');
        container.replaceChildren();
        permissions.forEach((permission) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'template_permissions[]';
            input.value = permission;
            container.append(input);
        });
        saveForm.requestSubmit();
    });
});
</script>
@endsection
