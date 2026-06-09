@extends('theme::layouts.app', ['title' => 'Регистрация'])

@php
    use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
@endphp

@section('content')
    <section id="register" class="register-section first-screen px-1">
        <div class="inner">
            <div class="section-heading">
                <h1 class="mb-4">Регистрация</h1>
            </div>

            <div class="section-content">
                @auth
                    <div class="alert alert-success">
                        Вы уже вошли как {{ auth()->user()->username }}.
                    </div>
                @else
                    <div class="auth-form-wrapper" style="max-width: 400px;">
                        @if ($errors->any())
                            <div class="alert alert-danger">
                                <ul class="mb-0">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('auth.register') }}">
                            @csrf

                            <div class="form-group field mb-3">
                                <label for="registerUsername">Логин</label>
                                <input
                                    id="registerUsername"
                                    type="text"
                                    name="username"
                                    placeholder="Логин"
                                    class="form-control @error('username') is-invalid @enderror"
                                    value="{{ old('username') }}"
                                    required
                                    autocomplete="username"
                                >
                                @error('username')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="form-group field mb-3">
                                <label for="registerPassword">Пароль</label>
                                <input
                                    id="registerPassword"
                                    type="password"
                                    name="password"
                                    placeholder="Пароль"
                                    class="form-control @error('password') is-invalid @enderror"
                                    required
                                    autocomplete="new-password"
                                >
                                @error('password')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="form-group field mb-3">
                                <label for="registerPasswordConfirmation">Подтверждение пароля</label>
                                <input
                                    id="registerPasswordConfirmation"
                                    type="password"
                                    name="password_confirmation"
                                    placeholder="Повторите пароль"
                                    class="form-control"
                                    required
                                    autocomplete="new-password"
                                >
                            </div>

                            <div class="form-group field mb-3">
                                <label for="registerRole">Роль на проекте</label>
                                <select
                                    id="registerRole"
                                    name="role"
                                    class="form-select @error('role') is-invalid @enderror"
                                >
                                    <option value="">Выбрать позже</option>
                                    @foreach(UserParticipationRoleEnum::cases() as $role)
                                        <option value="{{ $role->value }}" @selected(old('role') === $role->value)>
                                            {{ $role->label() }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('role')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <button type="submit" class="btn btn--secondary-bordered btn--sm">Зарегистрироваться</button>
                        </form>

                        <hr>

                        <div class="links">
                            <a href="{{ route('login') }}">Уже есть аккаунт?</a>
                        </div>
                    </div>
                @endauth
            </div>
        </div>
    </section>
@endsection
