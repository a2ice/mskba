@php $title = 'Дубли пользователей'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Кандидаты на объединение аккаунтов. Canonical user выбирается явно.',
])

@section('section-content')
    @php($openDuplicateId = (int) session('open_user_duplicate_id', 0))

    @if(session('success'))
        <div class="admin-empty" role="status">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="admin-empty" role="alert">{{ session('error') }}</div>
    @endif

    @if($errors->any())
        <div class="admin-empty" role="alert" tabindex="-1">
            <strong>Не удалось выполнить действие.</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="GET" action="{{ route('admin.users.duplicates') }}" class="admin-filter">
        <label class="admin-filter__field">
            <span class="admin-filter__label">Статус</span>
            <select class="form-select" name="status">
                <option value="">Все</option>
                @foreach($statuses as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </label>
        <div class="admin-filter__actions">
            <button type="submit" class="btn btn--primary btn--sm">Фильтр</button>
            <a href="{{ route('admin.users.duplicates') }}" class="btn btn--secondary btn--sm">Сброс</a>
        </div>
    </form>

    @if($duplicates->count() === 0)
        <div class="admin-empty">Кандидатов на объединение пока нет.</div>
    @else
        <div class="admin-table-wrap">
            <table class="admin-table admin-table--user-duplicates">
                <thead>
                    <tr>
                        <th>Пара</th>
                        <th>Доказательства</th>
                        <th>Score</th>
                        <th>Статус</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($duplicates as $duplicate)
                        @php
                            $first = $duplicate->user;
                            $second = $duplicate->duplicateUser;
                            $label = static function ($user): string {
                                $profile = $user?->profile;
                                $name = trim(implode(' ', array_filter([
                                    $profile?->first_name,
                                    $profile?->last_name,
                                ])));
                                return $name !== '' ? $name : ($user?->username ?: 'user #' . $user?->id);
                            };
                            $activeEvidence = $duplicate->evidence->where('is_active', true);
                        @endphp
                        <tr>
                            <td>
                                <div><strong>#{{ $first?->id }}</strong> · {{ $label($first) }}</div>
                                <div class="admin-muted">{{ $first?->system_role?->label() ?? '—' }} · {{ $first?->status?->label() ?? '—' }}</div>
                                <div class="admin-user-duplicate-pair__separator">и</div>
                                <div><strong>#{{ $second?->id }}</strong> · {{ $label($second) }}</div>
                                <div class="admin-muted">{{ $second?->system_role?->label() ?? '—' }} · {{ $second?->status?->label() ?? '—' }}</div>
                            </td>
                            <td>
                                @forelse($activeEvidence as $evidence)
                                    <span class="admin-badge">{{ $evidence->type->label() }}</span>
                                @empty
                                    <span class="admin-muted">Актуальных подтверждений больше нет</span>
                                @endforelse
                            </td>
                            <td>{{ $duplicate->score ?? '—' }}</td>
                            <td>
                                <button
                                    type="button"
                                    class="admin-badge admin-status-button"
                                    data-admin-action-modal-open="user-duplicate-{{ $duplicate->id }}"
                                    aria-haspopup="dialog"
                                >{{ $duplicate->status->label() }}</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @foreach($duplicates as $duplicate)
            @php
                $first = $duplicate->user;
                $second = $duplicate->duplicateUser;
                $label = static function ($user): string {
                    $name = trim(implode(' ', array_filter([$user?->profile?->first_name, $user?->profile?->last_name])));
                    return $name !== '' ? $name : ($user?->username ?: 'user #'.$user?->id);
                };
                $roles = collect([$first?->canonical()?->system_role, $second?->canonical()?->system_role])->filter();
                $hasElevatedRole = $roles->contains(fn ($role) => $role !== \App\Modules\Identity\Domain\Enums\UserSystemRoleEnum::USER);
                $hasProtectedRole = $roles->contains(fn ($role) => in_array($role, [
                    \App\Modules\Identity\Domain\Enums\UserSystemRoleEnum::SUPERADMIN,
                    \App\Modules\Identity\Domain\Enums\UserSystemRoleEnum::SYSTEM,
                ], true));
                $activeEvidence = $duplicate->evidence->where('is_active', true);
            @endphp
            <div class="admin-action-modal" data-admin-action-modal="user-duplicate-{{ $duplicate->id }}" @if($openDuplicateId !== (int) $duplicate->id) hidden @endif>
                <div class="admin-action-modal__backdrop" data-admin-action-modal-close></div>
                <section class="admin-action-modal__dialog admin-action-modal__dialog--wide" role="dialog" aria-modal="true" aria-labelledby="userDuplicateTitle{{ $duplicate->id }}">
                    <button type="button" class="admin-action-modal__close" data-admin-action-modal-close aria-label="Закрыть"></button>
                    <h2 id="userDuplicateTitle{{ $duplicate->id }}" class="admin-action-modal__title">Проверка пары #{{ $duplicate->id }}</h2>
                    <p class="admin-action-modal__description">Score: {{ $duplicate->score ?? '—' }} · {{ $duplicate->status->label() }}</p>

                    <div class="admin-user-duplicate-grid">
                        @foreach([$first, $second] as $candidate)
                            <article class="admin-user-duplicate-card">
                                <h3>#{{ $candidate?->id }} · {{ $label($candidate) }}</h3>
                                <p>{{ $candidate?->system_role?->label() ?? '—' }} · {{ $candidate?->status?->label() ?? '—' }}</p>
                                <p>{{ $candidate?->registration_channel?->label() ?? 'Канал не указан' }}</p>
                                <p>{{ $candidate?->telegramAccount?->username ? '@'.$candidate->telegramAccount->username : 'Telegram не привязан' }}</p>
                                <p>Создан: {{ $candidate?->created_at?->format('d.m.Y H:i') ?? '—' }}</p>
                            </article>
                        @endforeach
                    </div>

                    <div class="admin-user-duplicate-evidence">
                        <h3>Доказательства</h3>
                        @forelse($activeEvidence as $evidence)
                            <div>
                                <span class="admin-badge">{{ $evidence->type->label() }}</span>
                                <span class="admin-muted">{{ $evidence->metadata['source'] ?? 'unknown' }} · {{ $evidence->last_seen_at?->format('d.m.Y H:i') ?? '—' }}</span>
                            </div>
                        @empty
                            <p class="admin-muted">Актуальных подтверждений больше нет.</p>
                        @endforelse
                    </div>

                    @if($duplicate->status === \App\Modules\Identity\Domain\Enums\UserDuplicateStatusEnum::PENDING)
                        @if($hasProtectedRole)
                            <div class="admin-empty">Объединение запрещено: в паре есть суперадминистратор или системный пользователь.</div>
                        @elseif($activeEvidence->isEmpty())
                            <div class="admin-empty">Нельзя объединить пару без актуальных подтверждений.</div>
                        @else
                            <form method="POST" action="{{ route('admin.users.duplicates.merge', $duplicate) }}" class="admin-user-duplicate-form">
                                @csrf
                                <h3>Объединить аккаунты</h3>
                                @if($openDuplicateId === (int) $duplicate->id && $errors->any())
                                    <div class="admin-empty" role="alert" tabindex="-1">
                                        @foreach($errors->all() as $error)
                                            <div>{{ $error }}</div>
                                        @endforeach
                                    </div>
                                @endif
                                <label><input type="radio" name="canonical_user_id" value="{{ $first->id }}" @checked($openDuplicateId === (int) $duplicate->id && (int) old('canonical_user_id') === (int) $first->id) required> Основной #{{ $first->id }} · {{ $label($first) }}</label>
                                <label><input type="radio" name="canonical_user_id" value="{{ $second->id }}" @checked($openDuplicateId === (int) $duplicate->id && (int) old('canonical_user_id') === (int) $second->id) required> Основной #{{ $second->id }} · {{ $label($second) }}</label>
                                <label class="admin-user-duplicate-confirm"><input type="checkbox" name="confirm_merge" value="1" @checked($openDuplicateId === (int) $duplicate->id && old('confirm_merge')) required><span>Я проверил оба аккаунта и понимаю, что способы входа alias будут давать доступ к основному аккаунту.</span></label>
                                @if($hasElevatedRole)
                                    <label class="admin-user-duplicate-confirm"><input type="checkbox" name="confirm_privileged" value="1" @checked($openDuplicateId === (int) $duplicate->id && old('confirm_privileged')) required><span>Я отдельно проверил системные роли и подтверждаю объединение аккаунта с расширенными правами.</span></label>
                                @endif
                                <button class="btn btn--primary btn--sm" type="submit">Объединить аккаунты</button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('admin.users.duplicates.reject', $duplicate) }}" class="admin-user-duplicate-form">
                            @csrf
                            <label>Причина отклонения (необязательно)<textarea name="reason" rows="2" maxlength="1000"></textarea></label>
                            <button class="btn btn--secondary btn--sm" type="submit">Не дубли</button>
                        </form>
                    @else
                        <p class="admin-muted">Решение принято: {{ $duplicate->resolved_at?->format('d.m.Y H:i') ?? '—' }}</p>
                    @endif
                </section>
            </div>
        @endforeach

        @include('theme::partials.admin.pagination', ['paginator' => $duplicates])
    @endif
@endsection
