@php $types = isset($types) ? $types : []; @endphp

@php $title = 'Добавить площадку'; @endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])

@section('section-content')
    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

    <form method="POST" action="{{ route('account.venues.store') }}">
        @csrf

        <div class="mb-3">
            <label for="venueName" class="form-label">Название</label>
            <input
                id="venueName"
                type="text"
                name="name"
                class="form-control @error('name') is-invalid @enderror"
                value="{{ old('name') }}"
                required
            >
            @error('name')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="venueType" class="form-label">Тип площадки</label>
            <select
                id="venueType"
                name="type"
                class="form-select @error('type') is-invalid @enderror"
                required
            >
                <option value="">Выберите тип</option>
                @foreach ($types as $type)
                    <option value="{{ $type->value }}" @selected(old('type') === $type->value)>
                        {{ $type->label() }}
                    </option>
                @endforeach
            </select>
            @error('type')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="venueDescription" class="form-label">Описание</label>
            <textarea
                id="venueDescription"
                name="description"
                class="form-control @error('description') is-invalid @enderror"
                rows="5"
            >{{ old('description') }}</textarea>
            @error('description')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="venueRawAddress" class="form-label">Адрес</label>
            <textarea
                id="venueRawAddress"
                name="raw_address"
                class="form-control @error('raw_address') is-invalid @enderror"
                rows="3"
                placeholder="Например: Москва, ул. Летниковская, 12"
            >{{ old('raw_address') }}</textarea>
            @error('raw_address')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-success">Добавить</button>
        <a href="{{ route('account.venues') }}" class="btn btn-link">Отмена</a>
    </form>
@endsection
