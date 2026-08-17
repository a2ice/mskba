@php $title = 'Дубли пользователей'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Кандидаты на объединение аккаунтов. Canonical user выбирается явно.',
])

@section('section-content')
    @if(session('success'))
        <div class="admin-empty">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="admin-empty">{{ session('error') }}</div>
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
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Пара</th>
                        <th>Доказательства</th>
                        <th>Score</th>
                        <th>Статус</th>
                        <th>Решение</th>
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
                            $roles = collect([$first?->canonical()?->system_role, $second?->canonical()?->system_role])->filter();
                            $hasElevatedRole = $roles->contains(fn ($role) => $role !== \App\Modules\Identity\Domain\Enums\UserSystemRoleEnum::USER);
                            $hasProtectedRole = $roles->contains(fn ($role) => in_array($role, [
                                \App\Modules\Identity\Domain\Enums\UserSystemRoleEnum::SUPERADMIN,
                                \App\Modules\Identity\Domain\Enums\UserSystemRoleEnum::SYSTEM,
                            ], true));
                        @endphp
                        <tr>
                            <td>
                                <div><strong>#{{ $first?->id }}</strong> · {{ $label($first) }}</div>
                                <div class="admin-muted">
                                    {{ $first?->system_role?->label() ?? '—' }} · {{ $first?->status?->label() ?? '—' }} · {{ $first?->registration_channel?->label() ?? '—' }}
                                </div>
                                <div class="admin-muted">{{ $first?->telegramAccount?->username ? '@'.$first->telegramAccount->username : 'Telegram не привязан' }}</div>
                                <div class="admin-muted">Создан: {{ $first?->created_at?->format('d.m.Y H:i') ?? '—' }}</div>

                                <div style="margin-top:.75rem"><strong>#{{ $second?->id }}</strong> · {{ $label($second) }}</div>
                                <div class="admin-muted">
                                    {{ $second?->system_role?->label() ?? '—' }} · {{ $second?->status?->label() ?? '—' }} · {{ $second?->registration_channel?->label() ?? '—' }}
                                </div>
                                <div class="admin-muted">{{ $second?->telegramAccount?->username ? '@'.$second->telegramAccount->username : 'Telegram не привязан' }}</div>
                                <div class="admin-muted">Создан: {{ $second?->created_at?->format('d.m.Y H:i') ?? '—' }}</div>
                            </td>
                            <td>
                                @forelse($duplicate->evidence->where('is_active', true) as $evidence)
                                    <div style="margin-bottom:.35rem">
                                        <span class="admin-badge">{{ $evidence->type->label() }}</span>
                                        <div class="admin-muted">
                                            {{ $evidence->metadata['source'] ?? 'unknown' }} · последнее подтверждение {{ $evidence->last_seen_at?->format('d.m.Y H:i') ?? '—' }}
                                        </div>
                                    </div>
                                @empty
                                    <span class="admin-muted">Актуальных подтверждений больше нет</span>
                                @endforelse
                            </td>
                            <td>{{ $duplicate->score ?? '—' }}</td>
                            <td><span class="admin-badge">{{ $duplicate->status->label() }}</span></td>
                            <td>
                                @if($duplicate->status === \App\Modules\Identity\Domain\Enums\UserDuplicateStatusEnum::PENDING)
                                    @if($hasProtectedRole)
                                        <div class="admin-muted">
                                            Объединение через механизм дублей запрещено: в паре есть суперадминистратор или системный пользователь.
                                        </div>
                                    @elseif($duplicate->evidence->where('is_active', true)->isEmpty())
                                        <div class="admin-muted">Нельзя объединить пару без актуальных подтверждений.</div>
                                    @else
                                        <form method="POST" action="{{ route('admin.users.duplicates.merge', $duplicate) }}" style="display:grid;gap:.5rem;min-width:240px">
                                            @csrf
                                            <label>
                                                <input type="radio" name="canonical_user_id" value="{{ $first->id }}" required>
                                                Основной #{{ $first->id }}
                                            </label>
                                            <label>
                                                <input type="radio" name="canonical_user_id" value="{{ $second->id }}" required>
                                                Основной #{{ $second->id }}
                                            </label>
                                            <label style="display:flex;gap:.4rem;align-items:flex-start">
                                                <input type="checkbox" name="confirm_merge" value="1" required>
                                                <span>Я проверил оба аккаунта и понимаю, что после объединения способы входа alias будут давать доступ к основному аккаунту.</span>
                                            </label>
                                            @if($hasElevatedRole)
                                                <label style="display:flex;gap:.4rem;align-items:flex-start">
                                                    <input type="checkbox" name="confirm_privileged" value="1" required>
                                                    <span>Я отдельно проверил системные роли и подтверждаю объединение аккаунта с расширенными правами.</span>
                                                </label>
                                            @endif
                                            <button class="btn btn--primary btn--sm" type="submit">Объединить аккаунты</button>
                                        </form>
                                    @endif

                                    <form method="POST" action="{{ route('admin.users.duplicates.reject', $duplicate) }}" style="margin-top:.5rem">
                                        @csrf
                                        <button class="btn btn--secondary btn--sm" type="submit">Не дубли</button>
                                    </form>
                                @else
                                    <span class="admin-muted">
                                        {{ $duplicate->resolved_at?->format('d.m.Y H:i') ?? '—' }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @include('theme::partials.admin.pagination', ['paginator' => $duplicates])
    @endif
@endsection
