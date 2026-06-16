@php $title = 'Аудит'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Журнал изменений ключевых сущностей проекта.',
])

@section('section-content')
    <form method="GET" action="{{ route('admin.audit') }}" class="admin-filter">
        <label class="admin-filter__field">
            <span class="admin-filter__label">Сущность</span>
            <select class="form-select" name="entity">
                <option value="">Все</option>
                @foreach($entities as $entity)
                    <option value="{{ $entity }}" @selected(($filters['entity'] ?? '') === $entity)>{{ class_basename($entity) }}</option>
                @endforeach
            </select>
        </label>
        <label class="admin-filter__field">
            <span class="admin-filter__label">Событие</span>
            <select class="form-select" name="event">
                <option value="">Все</option>
                @foreach($events as $event)
                    <option value="{{ $event }}" @selected(($filters['event'] ?? '') === $event)>{{ $event }}</option>
                @endforeach
            </select>
        </label>
        <label class="admin-filter__field">
            <span class="admin-filter__label">Actor</span>
            <input class="form-control" type="search" name="actor" value="{{ $filters['actor'] ?? '' }}" placeholder="Логин или actor key">
        </label>
        <div class="admin-filter__actions">
            <button type="submit" class="btn btn--primary btn--sm">Фильтр</button>
            <a href="{{ route('admin.audit') }}" class="btn btn--secondary btn--sm">Сброс</a>
        </div>
    </form>

    @if($logs->count() === 0)
        <div class="admin-empty">Записей аудита пока нет.</div>
    @else
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Дата</th>
                        <th>Сущность</th>
                        <th>Событие</th>
                        <th>Actor</th>
                        <th>Изменения</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($logs as $log)
                        @php
                            $actor = $log->actor;
                            $actorLabel = match (true) {
                                $actor?->user !== null => $actor->user->username,
                                $actor?->type !== null => $actor->type->value,
                                default => '—',
                            };
                            $oldValues = $log->old_values ?? [];
                            $newValues = $log->new_values ?? [];
                        @endphp
                        <tr>
                            <td>{{ $log->id }}</td>
                            <td>{{ $log->created_at?->format('d.m.Y H:i') }}</td>
                            <td>
                                <div>{{ class_basename($log->auditable_type) }}</div>
                                <div class="admin-muted">#{{ $log->auditable_id }}</div>
                            </td>
                            <td><span class="admin-badge">{{ $log->event }}</span></td>
                            <td>
                                <div>{{ $actorLabel }}</div>
                                @if($actor)
                                    <div class="admin-muted">{{ $actor->type->value }}</div>
                                @endif
                            </td>
                            <td>
                                <details>
                                    <summary>{{ count($newValues ?: $oldValues) }} полей</summary>
                                    <pre class="admin-code">{{ json_encode(['old' => $oldValues, 'new' => $newValues], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @include('theme::partials.admin.pagination', ['paginator' => $logs])
    @endif
@endsection
