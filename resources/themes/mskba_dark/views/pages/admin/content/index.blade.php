@php $title = 'Контент'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Материалы новостной ленты и публикации в Telegram.',
])

@section('section-heading-action')
    <div class="content-admin-actions">
        <a class="btn btn--secondary btn--sm" href="{{ route('admin.content.seo') }}">SEO страниц</a>
        <a class="btn btn--primary btn--sm" href="{{ route('admin.content.create') }}">Добавить материал</a>
    </div>
@endsection

@section('section-content')
    @if(session('status')) <div class="alert alert-success mb-3">{{ session('status') }}</div> @endif

    <form class="admin-filter mb-4" method="GET" action="{{ route('admin.content') }}">
            <label class="admin-filter__field" for="contentFilterQuery">
                <span class="admin-filter__label">Поиск</span>
                <input id="contentFilterQuery" class="form-control" name="q" value="{{ request('q') }}" placeholder="Название, alias, описание">
            </label>
            <label class="admin-filter__field" for="contentFilterType">
                <span class="admin-filter__label">Тип</span>
                <select id="contentFilterType" class="form-select" name="type">
                    <option value="">Все</option>
                    @foreach($types as $type)
                        <option value="{{ $type->value }}" @selected(request('type') === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="admin-filter__field" for="contentFilterFeed">
                <span class="admin-filter__label">Лента</span>
                <select id="contentFilterFeed" class="form-select" name="feed">
                    <option value="">Все</option>
                    <option value="published" @selected(request('feed') === 'published')>Опубликован</option>
                    <option value="hidden" @selected(request('feed') === 'hidden')>Не опубликован</option>
                </select>
            </label>
            <label class="admin-filter__field" for="contentFilterTelegram">
                <span class="admin-filter__label">Telegram</span>
                <select id="contentFilterTelegram" class="form-select" name="telegram">
                    <option value="">Все</option>
                    <option value="published" @selected(request('telegram') === 'published')>Включён</option>
                    <option value="hidden" @selected(request('telegram') === 'hidden')>Выключен</option>
                </select>
            </label>
        <div class="admin-filter__actions">
            <button class="btn btn--primary btn--sm" type="submit">Фильтр</button>
            <a class="btn btn--secondary btn--sm" href="{{ route('admin.content') }}">Сброс</a>
        </div>
    </form>

    @if($contentItems->isEmpty())
        <div class="admin-empty">Материалов пока нет.</div>
    @else
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Тип</th>
                        <th>Лента</th>
                        <th>Telegram</th>
                        <th>Автор</th>
                        <th>Обновлён</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($contentItems as $contentItem)
                        @php
                            $authorName = trim(($contentItem->createdBy?->profile?->first_name ?? '').' '.($contentItem->createdBy?->profile?->last_name ?? ''))
                                ?: $contentItem->createdBy?->username
                                ?: '—';
                            $telegramPublished = $contentItem->telegramPublications->where('status', 'published')->count();
                            $telegramFailed = $contentItem->telegramPublications->where('status', 'failed')->count();
                        @endphp
                        <tr>
                            <td>{{ $contentItem->id }}</td>
                            <td>
                                <strong>{{ $contentItem->title }}</strong>
                                <div class="admin-table__muted">{{ $contentItem->alias }}</div>
                            </td>
                            <td>{{ $contentItem->type->label() }}</td>
                            <td>
                                <span class="admin-badge {{ $contentItem->publish_in_feed ? 'admin-badge--success' : '' }}">
                                    {{ $contentItem->publish_in_feed ? 'Опубликован' : 'Не опубликован' }}
                                </span>
                            </td>
                            <td>
                                @if($contentItem->publish_in_telegram)
                                    <span class="admin-badge {{ $telegramFailed > 0 ? 'admin-badge--danger' : 'admin-badge--success' }}">
                                        {{ $telegramPublished }} опубликовано{{ $telegramFailed ? ', '.$telegramFailed.' с ошибкой' : '' }}
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $authorName }}</td>
                            <td>{{ $contentItem->updated_at?->format('d.m.Y H:i') }}</td>
                            <td>
                                <div class="content-admin-actions">
                                    <a class="btn btn--secondary btn--sm" href="{{ route('admin.content.edit', $contentItem->alias) }}">Редактировать</a>
                                    @if($contentItem->publish_in_feed)
                                        <a class="btn btn--secondary btn--sm" href="{{ route('news.show', $contentItem->alias) }}" target="_blank" rel="noopener">Просмотр</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @include('theme::partials.admin.pagination', ['paginator' => $contentItems])
    @endif
@endsection
