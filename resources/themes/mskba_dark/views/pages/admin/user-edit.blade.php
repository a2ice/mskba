@php $title = 'Редактирование пользователя'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => $editedUser->username.' · #'.$editedUser->id,
])

@section('section-content')
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(session('info'))
        <div class="alert alert-info">{{ session('info') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <nav class="admin-user-tabs" aria-label="Разделы пользователя">
        <a
            href="{{ route('admin.users.edit', ['user' => $editedUser, 'tab' => 'general']) }}"
            @class(['admin-user-tabs__link', 'is-active' => $activeTab === 'general'])
        >Общая информация</a>
        <a
            href="{{ route('admin.users.edit', ['user' => $editedUser, 'tab' => 'profile']) }}"
            @class(['admin-user-tabs__link', 'is-active' => $activeTab === 'profile'])
        >Профиль</a>
        <a
            href="{{ route('admin.users.edit', ['user' => $editedUser, 'tab' => 'roles']) }}"
            @class(['admin-user-tabs__link', 'is-active' => $activeTab === 'roles'])
        >Роли</a>
        <a
            href="{{ route('admin.users.edit', ['user' => $editedUser, 'tab' => 'history']) }}"
            @class(['admin-user-tabs__link', 'is-active' => $activeTab === 'history'])
        >История</a>
    </nav>

    @if($activeTab === 'general')
        <div class="admin-card">
            <form method="POST" action="{{ route('admin.users.update', $editedUser) }}">
                @csrf
                @method('PUT')

                <div class="row g-3">
                    <label class="col-12">
                        <span class="form-label">Логин</span>
                        <input class="form-control" type="text" value="{{ $editedUser->username }}" readonly>
                        <small>Логин не меняется на этой странице.</small>
                    </label>

                    <label class="col-12 col-md-6">
                        <span class="form-label">Имя</span>
                        <input class="form-control" type="text" name="first_name" maxlength="100" value="{{ old('first_name', $editedUser->profile?->first_name) }}">
                        @error('first_name')<small class="form-error">{{ $message }}</small>@enderror
                    </label>

                    <label class="col-12 col-md-6">
                        <span class="form-label">Фамилия</span>
                        <input class="form-control" type="text" name="last_name" maxlength="100" value="{{ old('last_name', $editedUser->profile?->last_name) }}">
                        @error('last_name')<small class="form-error">{{ $message }}</small>@enderror
                    </label>

                    <label class="col-12 col-md-6">
                        <span class="form-label">Отчество</span>
                        <input class="form-control" type="text" name="middle_name" maxlength="100" value="{{ old('middle_name', $editedUser->profile?->middle_name) }}">
                        @error('middle_name')<small class="form-error">{{ $message }}</small>@enderror
                    </label>

                    <label class="col-12 col-md-6">
                        <span class="form-label">Дата рождения</span>
                        <input class="form-control" type="date" name="birth_date" min="1900-01-01" max="{{ now()->toDateString() }}" value="{{ old('birth_date', $editedUser->profile?->birth_date?->toDateString()) }}">
                        <small>Возраст рассчитывается автоматически{{ $editedUser->profile?->age !== null ? ': '.$editedUser->profile->age : '' }}.</small>
                        @error('birth_date')<small class="form-error">{{ $message }}</small>@enderror
                    </label>
                </div>

                <section class="admin-user-registration-meta" aria-labelledby="registration-meta-title">
                    <div class="admin-user-registration-meta__header">
                        <h3 id="registration-meta-title">Мета-информация регистрации</h3>
                        <p>Источник регистрации и связанные внешние аккаунты.</p>
                    </div>

                    <dl class="admin-user-details">
                        <div class="admin-user-details__item">
                            <dt>Канал регистрации</dt>
                            <dd>{{ $editedUser->registration_channel?->label() ?? 'Не указан' }}</dd>
                        </div>
                        <div class="admin-user-details__item">
                            <dt>Создан</dt>
                            <dd>{{ $editedUser->created_at?->format('d.m.Y H:i:s') ?? '—' }}</dd>
                        </div>
                        <div class="admin-user-details__item">
                            <dt>Первый вход</dt>
                            <dd>{{ $editedUser->first_logged_in_at?->format('d.m.Y H:i:s') ?? 'Не зафиксирован' }}</dd>
                        </div>
                        <div class="admin-user-details__item">
                            <dt>Identity</dt>
                            <dd>#{{ $editedUser->id }}{{ $registrationAccounts->count() > 1 ? ' · связанных аккаунтов: '.$registrationAccounts->count() : '' }}</dd>
                        </div>
                    </dl>

                    @if($registrationAccounts->count() > 1)
                        <div class="admin-user-meta-provider">
                            <h4>Связанные локальные аккаунты</h4>
                            @foreach($registrationAccounts as $identityAccount)
                                <div class="admin-user-meta-provider__row">
                                    <span>#{{ $identityAccount->id }} · {{ $identityAccount->username }}</span>
                                    <span class="admin-muted">{{ $identityAccount->registration_channel?->label() ?? 'Канал не указан' }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @foreach($telegramAccounts as $telegramAccount)
                        <div class="admin-user-meta-provider">
                            <div class="admin-user-meta-provider__title">
                                <h4>Telegram</h4>
                                <span class="admin-badge">TG ID {{ $telegramAccount->telegram_user_id }}</span>
                            </div>
                            <dl class="admin-user-details">
                                <div class="admin-user-details__item">
                                    <dt>Username</dt>
                                    <dd>{{ $telegramAccount->username ? '@'.$telegramAccount->username : '—' }}</dd>
                                </div>
                                <div class="admin-user-details__item">
                                    <dt>Имя в Telegram</dt>
                                    <dd>{{ trim(($telegramAccount->first_name ?? '').' '.($telegramAccount->last_name ?? '')) ?: '—' }}</dd>
                                </div>
                                <div class="admin-user-details__item">
                                    <dt>Язык</dt>
                                    <dd>{{ $telegramAccount->language_code ?: '—' }}</dd>
                                </div>
                                <div class="admin-user-details__item">
                                    <dt>Последняя авторизация</dt>
                                    <dd>{{ $telegramAccount->last_auth_at?->format('d.m.Y H:i:s') ?? '—' }}</dd>
                                </div>
                                <div class="admin-user-details__item">
                                    <dt>Локальный аккаунт</dt>
                                    <dd>#{{ $telegramAccount->user_id }}</dd>
                                </div>
                            </dl>
                        </div>
                    @endforeach

                    @foreach($vkAccounts as $vkAccount)
                        <div class="admin-user-meta-provider">
                            <div class="admin-user-meta-provider__title">
                                <h4>VK ID</h4>
                                <span class="admin-badge">VK ID {{ $vkAccount->vk_user_id }}</span>
                            </div>
                            <dl class="admin-user-details">
                                <div class="admin-user-details__item">
                                    <dt>Имя в VK</dt>
                                    <dd>{{ trim(($vkAccount->first_name ?? '').' '.($vkAccount->last_name ?? '')) ?: '—' }}</dd>
                                </div>
                                <div class="admin-user-details__item">
                                    <dt>Последняя авторизация</dt>
                                    <dd>{{ $vkAccount->last_auth_at?->format('d.m.Y H:i:s') ?? '—' }}</dd>
                                </div>
                                <div class="admin-user-details__item">
                                    <dt>Локальный аккаунт</dt>
                                    <dd>#{{ $vkAccount->user_id }}</dd>
                                </div>
                            </dl>
                        </div>
                    @endforeach

                    @if($acquisitionVisits->isNotEmpty())
                        <div class="admin-user-meta-provider">
                            <h4>Источники привлечения</h4>
                            <div class="admin-user-acquisition-list">
                                @foreach($acquisitionVisits as $visit)
                                    <article class="admin-user-acquisition">
                                        <div class="admin-user-acquisition__head">
                                            <strong>{{ $visit->campaign?->name ?: ($visit->campaign_name ?: 'Без кампании') }}</strong>
                                            <span class="admin-badge">{{ $visit->channel->label() }}</span>
                                        </div>
                                        <div class="admin-user-acquisition__meta">
                                            @if($visit->source || $visit->medium)
                                                <span>Источник: {{ $visit->source ?: '—' }}{{ $visit->medium ? ' / '.$visit->medium : '' }}</span>
                                            @endif
                                            @if($visit->persona)
                                                <span>Сценарий: {{ $visit->persona->label() }}</span>
                                            @endif
                                            <span>Визит: {{ $visit->visited_at?->format('d.m.Y H:i:s') ?? '—' }}</span>
                                            <span>Landing: {{ $visit->landing_path }}</span>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if($telegramAccounts->isEmpty() && $vkAccounts->isEmpty() && $acquisitionVisits->isEmpty())
                        <p class="admin-muted admin-user-registration-meta__empty">Дополнительных данных Telegram, VK или acquisition не найдено.</p>
                    @endif
                </section>

                <hr class="my-4">

                <h3>Временный пароль</h3>
                <p>Оставьте поля пустыми, чтобы не менять пароль. Новый пароль будет временным: пользователь должен заменить его в настройках аккаунта.</p>

                <div class="row g-3">
                    <label class="col-12 col-md-6">
                        <span class="form-label">Новый пароль</span>
                        <input class="form-control" type="password" name="password" autocomplete="new-password">
                        @error('password')<small class="form-error">{{ $message }}</small>@enderror
                    </label>

                    <label class="col-12 col-md-6">
                        <span class="form-label">Повторите пароль</span>
                        <input class="form-control" type="password" name="password_confirmation" autocomplete="new-password">
                    </label>
                </div>

                <div class="d-flex flex-wrap gap-3 mt-4">
                    <button type="submit" class="btn btn--primary">Сохранить</button>
                    <a href="{{ route('admin.users') }}" class="btn btn--secondary">Назад к пользователям</a>
                </div>
            </form>

            <div class="admin-user-danger-zone">
                @if(! $editedUser->is(auth()->user()))
                    <h3 class="admin-user-danger-zone__title">Удаление аккаунта</h3>
                    <p class="admin-user-danger-zone__text">Аккаунт будет удалён мягко и останется доступен для восстановления из списка удалённых пользователей.</p>
                    <form
                        method="POST"
                        action="{{ route('admin.users.bulk-delete') }}"
                        onsubmit="return confirm('вы уверены что хотите удалить аккаунт')"
                    >
                        @csrf
                        <input type="hidden" name="user_ids[]" value="{{ $editedUser->id }}">
                        <button type="submit" class="btn btn--danger">Удалить</button>
                    </form>
                @else
                    <p class="admin-user-danger-zone__text mb-0">Собственный аккаунт удаляется со страницы личного кабинета.</p>
                @endif
            </div>
        </div>
    @elseif($activeTab === 'profile')
        <div class="admin-card">
            <dl class="admin-user-details">
                <div class="admin-user-details__item">
                    <dt>Логин</dt>
                    <dd>{{ $editedUser->username }}</dd>
                </div>
                <div class="admin-user-details__item">
                    <dt>Никнейм</dt>
                    <dd>{{ $editedUser->nickname ?: '—' }}</dd>
                </div>
                <div class="admin-user-details__item">
                    <dt>Статус</dt>
                    <dd>{{ $editedUser->status->label() }}</dd>
                </div>
                <div class="admin-user-details__item">
                    <dt>Системная роль</dt>
                    <dd>{{ $editedUser->system_role->label() }}</dd>
                </div>
                <div class="admin-user-details__item">
                    <dt>Имя</dt>
                    <dd>{{ $editedUser->profile?->first_name ?: '—' }}</dd>
                </div>
                <div class="admin-user-details__item">
                    <dt>Фамилия</dt>
                    <dd>{{ $editedUser->profile?->last_name ?: '—' }}</dd>
                </div>
                <div class="admin-user-details__item">
                    <dt>Отчество</dt>
                    <dd>{{ $editedUser->profile?->middle_name ?: '—' }}</dd>
                </div>
                <div class="admin-user-details__item">
                    <dt>Пол</dt>
                    <dd>{{ $editedUser->profile?->gender?->label() ?? '—' }}</dd>
                </div>
                <div class="admin-user-details__item">
                    <dt>Дата рождения</dt>
                    <dd>{{ $editedUser->profile?->birth_date?->format('d.m.Y') ?? '—' }}</dd>
                </div>
                <div class="admin-user-details__item">
                    <dt>Возраст</dt>
                    <dd>{{ $editedUser->profile?->age ?? '—' }}</dd>
                </div>
                <div class="admin-user-details__item">
                    <dt>Основной email</dt>
                    <dd>{{ $editedUser->primaryEmail()?->displayValue() ?? '—' }}</dd>
                </div>
                <div class="admin-user-details__item">
                    <dt>Дата регистрации</dt>
                    <dd>{{ $editedUser->created_at?->format('d.m.Y H:i') ?? '—' }}</dd>
                </div>
            </dl>
        </div>
    @elseif($activeTab === 'roles')
        <div class="admin-card">
            <h3>Роли в проекте</h3>
            <ul class="admin-user-role-list mb-4">
                @forelse($editedUser->participationRoles as $participationRole)
                    <li class="admin-user-role-list__item">
                        <strong>{{ $participationRole->role->label() }}</strong>
                        <span class="admin-badge">{{ $participationRole->status->label() }}</span>
                    </li>
                @empty
                    <li class="admin-muted">Роли участия не назначены.</li>
                @endforelse
            </ul>

            <h3>Операционные права</h3>
            @php
                $permissionSnapshot = $editedUser->operationalPermissions
                    ->keyBy(fn ($entry) => $entry->permission->value);
            @endphp
            <ul class="admin-user-role-list">
                @foreach($operationalPermissions as $permission)
                    @php
                        $isAllowed = $permissionSnapshot->get($permission->value)?->is_allowed
                            ?? $permission->defaultAllowedFor($editedUser->system_role);
                    @endphp
                    <li class="admin-user-role-list__item">
                        <span>{{ $permission->label() }}</span>
                        <span class="admin-badge">{{ $isAllowed ? 'Разрешено' : 'Запрещено' }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @else
        <div class="admin-card">
            <p class="admin-muted">Последние 50 действий пользователя, попавших в общий аудит.</p>

            @if($auditLogs->isEmpty())
                <div class="admin-empty">Действий в журнале пока нет.</div>
            @else
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Действие</th>
                                <th>Объект</th>
                                <th>ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($auditLogs as $log)
                                <tr>
                                    <td>{{ $log->created_at?->format('d.m.Y H:i:s') }}</td>
                                    <td>{{ $log->event }}</td>
                                    <td>{{ class_basename($log->auditable_type) }}</td>
                                    <td>{{ $log->auditable_id }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
@endsection
