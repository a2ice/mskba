@php $title = 'Привлечение'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Кампании внешнего привлечения, QR-точки и базовая attribution-аналитика.',
])

@section('section-content')
    <div class="admin-section-toolbar">
        <div>
            <p class="admin-muted mb-0">
                Одна площадка может иметь несколько кампаний: например стенд, вход и раздаточную листовку.
            </p>
        </div>
        <a href="{{ route('admin.acquisition.create') }}" class="btn btn--primary btn--sm">
            <i class="ti ti-plus" aria-hidden="true"></i>
            Создать кампанию
        </a>
    </div>

    <form method="GET" action="{{ route('admin.acquisition.index') }}" class="admin-filter">
        <label class="admin-filter__field">
            <span class="admin-filter__label">Поиск</span>
            <input
                class="form-control"
                type="search"
                name="search"
                value="{{ $filters['search'] ?? '' }}"
                placeholder="Название, код или площадка"
            >
        </label>
        <label class="admin-filter__field">
            <span class="admin-filter__label">Канал</span>
            <select class="form-select" name="channel">
                <option value="">Все</option>
                @foreach($channels as $channel)
                    <option value="{{ $channel->value }}" @selected(($filters['channel'] ?? '') === $channel->value)>
                        {{ $channel->label() }}
                    </option>
                @endforeach
            </select>
        </label>
        <label class="admin-filter__field">
            <span class="admin-filter__label">Статус</span>
            <select class="form-select" name="active">
                <option value="">Все</option>
                <option value="1" @selected(($filters['active'] ?? '') === '1')>Активные</option>
                <option value="0" @selected(($filters['active'] ?? '') === '0')>Выключенные</option>
            </select>
        </label>
        <div class="admin-filter__actions">
            <button type="submit" class="btn btn--primary btn--sm">Фильтр</button>
            <a href="{{ route('admin.acquisition.index') }}" class="btn btn--secondary btn--sm">Сброс</a>
        </div>
    </form>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if($campaigns->count() === 0)
        <div class="admin-empty">Кампании не найдены.</div>
    @else
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Кампания</th>
                        <th>Канал</th>
                        <th>Площадка / размещение</th>
                        <th>Статус</th>
                        <th>Переходы</th>
                        <th>Пользователи</th>
                        <th>На месте</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($campaigns as $campaign)
                        <tr>
                            <td>
                                <strong>{{ $campaign->name }}</strong>
                                <div class="admin-muted">
                                    <code>{{ $campaign->public_code }}</code>
                                </div>
                            </td>
                            <td>{{ $campaign->channel->label() }}</td>
                            <td>
                                @if($campaign->venue)
                                    <strong>{{ $campaign->venue->name }}</strong>
                                @else
                                    <span class="admin-muted">Без площадки</span>
                                @endif
                                @if(filled(data_get($campaign->metadata, 'placement')))
                                    <div class="admin-muted">{{ data_get($campaign->metadata, 'placement') }}</div>
                                @endif
                            </td>
                            <td>
                                @php($campaignState = $campaign->state())
                                <span @class(['admin-badge', 'admin-badge--muted' => ! $campaign->isAvailable()])>
                                    {{ $campaignState->label() }}
                                </span>
                            </td>
                            <td>{{ $campaign->visits_count }}</td>
                            <td>{{ $campaign->linked_users_count }}</td>
                            <td>{{ $campaign->verified_visits_count }}</td>
                            <td>
                                <div class="admin-row-actions">
                                    <a href="{{ route('admin.acquisition.edit', $campaign) }}" class="btn btn--secondary btn--sm">Открыть</a>
                                    <a href="{{ route('acquisition.entry', ['campaignCode' => $campaign->public_code]) }}" class="btn btn--secondary btn--sm" target="_blank" rel="noopener">/go</a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @include('theme::partials.admin.pagination', ['paginator' => $campaigns])
    @endif
@endsection
