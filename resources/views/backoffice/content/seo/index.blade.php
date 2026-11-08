@php $title = 'SEO страниц'; @endphp

@extends('backoffice.content.layout', [
    'title' => $title,
    'subtitle' => 'Метаданные публичных страниц площадок, мероприятий и команд.',
])

@section('section-heading-action')
    <x-filament::button tag="a" color="gray" href="{{ route('admin.content') }}">Материалы</x-filament::button>
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
            <x-filament::button type="submit">Фильтр</x-filament::button>
            <x-filament::button tag="a" color="gray" href="{{ route('admin.content.seo', ['entity_type' => $selectedType->value]) }}">Сброс</x-filament::button>
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
                                <x-filament::button tag="a" color="gray" href="{{ route('admin.content.seo.edit', [$selectedType->value, $entity->id]) }}">
                                    Редактировать
                                </x-filament::button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @include('backoffice.content.pagination', ['paginator' => $entities])
    @endif
@endsection
