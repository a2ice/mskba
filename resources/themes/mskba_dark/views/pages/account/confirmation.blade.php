@php
    $title = 'Подтверждение аккаунта';
    $isConfirmed = $user?->status === \App\Modules\Identity\Domain\Enums\UserStatusEnum::CONFIRMED;
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title, 'sectionId' => 'account',
    'sectionClass' => 'account-section', 'contentTitle' => $title,
    'sidebarLabel' => 'Навигация аккаунта', 'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])

@section('section-content')
    @if (isset($error)) <div class="alert alert-danger">{{ $error['message'] }}</div> @endif
    @if (session('status')) <div class="alert alert-success">{{ session('status') }}</div> @endif
    @if (session('error')) <div class="alert alert-danger">{{ session('error') }}</div> @endif
    @if (session('info')) <div class="alert alert-info">{{ session('info') }}</div> @endif

    @if ($user && $isConfirmed)
        <div class="alert alert-success">Аккаунт подтверждён.</div>
    @elseif ($user)
        <p>Для подтверждения аккаунта необходимы подтверждённый основной контакт, имя, фамилия, дата рождения и пол. Выбор роли участия не требуется.</p>
        <section class="panel p-4 mb-3" aria-labelledby="confirmation-contact">
            <h2 id="confirmation-contact" class="h5 mb-3">Основной контакт</h2>
            @if ($primaryVerifiedContact)
                <p class="mb-2"><strong>Основной контакт подтверждён</strong>: {{ $primaryVerifiedContact->type->label() }} — {{ $primaryVerifiedContact->displayValue() }}</p>
            @elseif ($primaryContact)
                <p>Основной контакт ожидает подтверждения: {{ $primaryContact->type->label() }} — {{ $primaryContact->displayValue() }}</p>
                @if ($primaryContact->type === \App\Modules\Contact\Domain\Enums\ContactTypeEnum::EMAIL)
                    <form method="POST" action="{{ route('account.confirmation.contacts.verification.store', $primaryContact) }}">
                        @csrf
                        <button type="submit" class="btn btn--secondary btn--sm">Отправить код</button>
                    </form>
                    @if ($primaryContactPendingVerification)
                        <form method="POST" action="{{ route('account.confirmation.contacts.verification.confirm', $primaryContact) }}" class="mt-3">
                            @csrf
                            <label for="confirmation-code" class="form-label">Код из письма</label>
                            <input id="confirmation-code" name="code" class="form-control mb-2" inputmode="numeric" autocomplete="one-time-code" value="{{ old('code') }}" required>
                            <button type="submit" class="btn btn--primary btn--sm">Подтвердить контакт</button>
                            @error('code') <small class="text-danger">{{ $message }}</small> @enderror
                        </form>
                    @endif
                @endif
                <p class="mt-3"><a href="{{ route('account.contacts') }}">Управлять контактами и выбрать основной</a></p>
            @else
                <form method="POST" action="{{ route('account.confirmation.contact.store') }}">
                    @csrf
                    <input type="hidden" name="type" value="email">
                    <label for="confirmation-email" class="form-label">Email</label>
                    <input id="confirmation-email" name="value" type="email" class="form-control mb-2" value="{{ old('value') }}" required placeholder="name@example.com">
                    <button class="btn btn--primary btn--sm" type="submit">Добавить и отправить код</button>
                    @error('value') <small class="text-danger">{{ $message }}</small> @enderror
                </form>
                <p class="mt-3"><a href="{{ route('account.contacts') }}">Другие способы связи (Telegram, VK)</a></p>
            @endif
            @error('contact') <p class="text-danger">{{ $message }}</p> @enderror
        </section>

        <form method="POST" action="{{ route('account.confirmation.complete') }}">
            @csrf
            <section class="panel p-4 mb-3" aria-labelledby="confirmation-identity">
                <h2 id="confirmation-identity" class="h5 mb-3">Персональные данные</h2>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="confirmation-first" class="form-label">Имя *</label>
                        <input id="confirmation-first" name="first_name" type="text" class="form-control"
                               maxlength="255" value="{{ old('first_name', $user->profile?->first_name) }}" required>
                        @error('first_name') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="confirmation-last" class="form-label">Фамилия *</label>
                        <input id="confirmation-last" name="last_name" type="text" class="form-control"
                               maxlength="255" value="{{ old('last_name', $user->profile?->last_name) }}" required>
                        @error('last_name') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="confirmation-birth" class="form-label">Дата рождения *</label>
                        <input id="confirmation-birth" name="birth_date" type="date" class="form-control"
                               max="{{ now()->subDay()->toDateString() }}" value="{{ old('birth_date', $user->profile?->birth_date?->toDateString()) }}" required>
                        @error('birth_date') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="confirmation-gender" class="form-label">Пол *</label>
                        <select id="confirmation-gender" name="gender" class="form-select" required>
                            <option value="">Выбери пол</option>
                            @foreach ($genders as $gender)
                                <option value="{{ $gender->value }}" @selected(old('gender', $user->profile?->gender?->value) === $gender->value)>{{ $gender->label() }}</option>
                            @endforeach
                        </select>
                        @error('gender') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                </div>
            </section>
            <p>После подтверждения имя, фамилию, дату рождения и пол можно изменить только по заявке.</p>
            <button type="submit" class="btn btn--primary btn--sm" @disabled(! $primaryVerifiedContact)>Подтвердить аккаунт</button>
        </form>
    @endif
@endsection
