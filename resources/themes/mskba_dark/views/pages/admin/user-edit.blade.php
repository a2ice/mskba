@php $title = 'Редактирование пользователя'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => $editedUser->username.' · #'.$editedUser->id,
])

@section('section-content')
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

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
    </div>
@endsection
