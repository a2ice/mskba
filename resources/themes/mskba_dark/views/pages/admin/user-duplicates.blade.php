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
                        @endphp
                        <tr>
                            <td>
                                <div><strong>#{{ $first?->id }}</strong> · {{ $label($first) }}</div>
                                <div class="admin-muted">{{ $first?->telegramAccount?->username ? '@'.$first->telegramAccount->username : 'Telegram не привязан' }}</div>
                                <div style="margin-top:.5rem"><strong>#{{ $second?->id }}</strong> · {{ $label($second) }}</div>
                                <div class="admin-muted">{{ $second?->telegramAccount?->username ? '@'.$second->telegramAccount->username : 'Telegram не привязан' }}</div>
                            </td>
                            <td>
                                @foreach($duplicate->evidence->where('is_active', true) as $evidence)
                                    <span class="admin-badge">{{ $evidence->type->label() }}</span>
                                @endforeach
                            </td>
                            <td>{{ $duplicate->score ?? '—' }}</td>
                            <td><span class="admin-badge">{{ $duplicate->status->label() }}</span></td>
                            <td>
                                @if($duplicate->status === \App\Modules\Identity\Domain\Enums\UserDuplicateStatusEnum::PENDING)
                                    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                                        <form method="POST" action="{{ route('admin.users.duplicates.merge', $duplicate) }}">
                                            @csrf
                                            <input type="hidden" name="canonical_user_id" value="{{ $first->id }}">
                                            <button class="btn btn--primary btn--sm" type="submit">Главный #{{ $first->id }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.users.duplicates.merge', $duplicate) }}">
                                            @csrf
                                            <input type="hidden" name="canonical_user_id" value="{{ $second->id }}">
                                            <button class="btn btn--primary btn--sm" type="submit">Главный #{{ $second->id }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.users.duplicates.reject', $duplicate) }}">
                                            @csrf
                                            <button class="btn btn--secondary btn--sm" type="submit">Не дубли</button>
                                        </form>
                                    </div>
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
