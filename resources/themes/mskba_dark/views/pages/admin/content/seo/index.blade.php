@php $title = 'SEO страниц'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Метаданные публичных страниц площадок, мероприятий и команд.',
])

@section('section-heading-action')
    <a class="btn btn--secondary btn--sm" href="{{ route('admin.content') }}">Материалы</a>
@endsection

@section('section-content')
    <form class="admin-filter mb-4" method="GET" action="{{ route('admin.content.seo') }}">
        <label class="admin-filter__field" for="pageSeoQuery">
            <span class="admin-filter__label">Поиск</span>
            <input id="pageSeoQuery" class="form-control" name="q" value="{{ request('q') }}" placeholder="Название страницы">
        </label>
        <label class="admin-filter__field" for="pageSeoType">
            <span class="admin-filter__label">Раздел</span>
            <select id="pageSeoType" class="form-select" name="entity_type">
                @foreach($types as $type)
                    <option value="{{ $type->value }}" @selected($selectedType === $type)>{{ $type->label() }}</option>
                @endforeach
            </select>
        </label>
        <div class="admin-filter__actions">
            <button class="btn btn--primary btn--sm" type="submit">Фильтр</button>
            <a class="btn btn--secondary btn--sm" href="{{ route('admin.content.seo', ['entity_type' => $selectedType->value]) }}">Сброс</a>
        </div>
    </form>

    @if($entities->isEmpty())
        <div class="admin-empty">Страниц по выбранным условиям нет.</div>
    @else
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Страница</th>
                        <th>Раздел</th>
                        <th>SEO</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($entities as $entity)
                        @php
                            $entityTitle = $selectedType->value === 'event' ? $entity->title : $entity->name;
                            $setting = $settings->get($entity->id);
                        @endphp
                        <tr>
                            <td>{{ $entity->id }}</td>
                            <td><strong>{{ $entityTitle }}</strong></td>
                            <td>{{ $selectedType->label() }}</td>
                            <td>
                                <span class="admin-badge {{ $setting ? 'admin-badge--success' : '' }}">
                                    {{ $setting ? 'Настроено' : 'По умолчанию' }}
                                </span>
                            </td>
                            <td>
                                <a class="btn btn--secondary btn--sm" href="{{ route('admin.content.seo.edit', [$selectedType->value, $entity->id]) }}">
                                    Редактировать
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @include('theme::partials.admin.pagination', ['paginator' => $entities])
    @endif
@endsection
