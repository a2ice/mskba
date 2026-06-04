@extends('theme::layouts.account', [
    'title' => 'Мои контакты',
])

@section('account-content')

    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

    @if ($user)

        @if(session('status'))
            <div class="alert alert-success">
                {{ session('status') }}
            </div>
        @endif

        @if(session('info'))
            <div class="alert alert-info" data-countdown-hide-on-finished>
                @if(session()->has('contactVerificationCooldownSeconds'))
                    Код уже отправлен. Новый код можно запросить через
                    <span class="countdown-wrapper">
                        <span
                            data-countdown
                            data-countdown-seconds="{{ session('contactVerificationCooldownSeconds') }}"
                        >{{ session('contactVerificationCooldownSeconds') }}</span>
                        сек.
                    </span>
                @else
                    {{ session('info') }}
                @endif
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger">
                {{ session('error') }}
            </div>
        @endif

        <div class="account-contacts">
            <div class="account-contacts__list mb-5">

                @if($user->contacts->isNotEmpty())
                    <ul class="list-unstyled mb-0">
                        @foreach($user->contacts as $contact)
                            @php
                                $pendingVerification = $contact->verifications->first();
                                $hasActivePendingVerification = $pendingVerification?->expires_at?->isFuture() ?? false;
                            @endphp
                            <li class="account-contact-item d-flex flex-wrap gap-3 align-center mb-3">
                                <div>
                                    <span class="fw-bold">{{ $contact->type->label() }}:</span>
                                    <span>{{ $contact->value }}</span>
                                    @if($contact->label)
                                        <span class="text-muted">({{ $contact->label }})</span>
                                    @endif
                                </div>
                                <div class="account-contact-item__meta gap-2 d-flex flex-wrap">
                                    @if($contact->is_primary)
                                        <span>Основной</span>
                                    @endif
                                    @if($contact->is_public)
                                        <span>Публичный</span>
                                    @endif
                                    <span class="{{ $contact->is_verified ? 'text-success' : 'text-danger' }}">{{ $contact->is_verified ? 'Подтвержден' : 'Не подтвержден' }}</span>
                                </div>
                                <div class="account-contact-item__actions">
                                    <form method="POST" action="{{ route('account.contacts.destroy', $contact) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn--secondary btn--sm">Удалить</button>
                                    </form>
                                </div>
                                @if(! $contact->is_verified && $contact->type === \App\Modules\Contact\Domain\Enums\ContactTypeEnum::EMAIL)
                                    <div class="account-contact-verification w-100">
                                        @if($hasActivePendingVerification)
                                            <div class="account-contact-verification__field mb-3">
                                                <form
                                                    id="contactVerificationConfirm{{ $contact->id }}"
                                                    method="POST"
                                                    action="{{ route('account.contacts.verification.confirm', $contact) }}"
                                                >
                                                    @csrf
                                                    <label for="contactVerificationCode{{ $contact->id }}" class="form-label">Код из письма</label>
                                                    <input
                                                        id="contactVerificationCode{{ $contact->id }}"
                                                        type="text"
                                                        name="code"
                                                        inputmode="numeric"
                                                        autocomplete="one-time-code"
                                                        maxlength="6"
                                                        class="form-control @error('code') is-invalid @enderror"
                                                        value="{{ old('code') }}"
                                                        placeholder="000000"
                                                        autofocus
                                                        required
                                                    >
                                                    @error('code')
                                                        <div class="invalid-feedback">{{ $message }}</div>
                                                    @enderror
                                                </form>
                                            </div>
                                        @endif
                                        <div class="account-contact-verification__actions">
                                            @if($hasActivePendingVerification)
                                                <button
                                                    type="submit"
                                                    form="contactVerificationConfirm{{ $contact->id }}"
                                                    class="btn btn--primary btn--sm"
                                                >Подтвердить</button>
                                            @endif
                                            <form method="POST" action="{{ route('account.contacts.verification.store', $contact) }}">
                                                @csrf
                                                <button type="submit" class="btn btn--secondary btn--sm">
                                                    {{ $hasActivePendingVerification ? 'Новый код' : 'Подтвердить' }}
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                @endif
                                @if(!$loop->last) 
                                    <hr class="w-100 mt-3 mb-0">
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mb-0">Контакты пока не добавлены.</p>
                @endif
            </div>

            <div class="account-contacts__form">
                
                <hr>

                <h2 class="h5 mb-3">Добавить контакт</h4>

                <form method="POST" action="{{ route('account.contacts.store') }}">
                    @csrf

                    <div class="mb-3 field">
                        <label for="contactType" class="form-label">Тип</label>
                        <select id="contactType" name="type" class="form-select @error('type') is-invalid @enderror" required>
                            @foreach($contactTypes as $type)
                                <option value="{{ $type->value }}" @selected(old('type') === $type->value)>
                                    {{ $type->label() }}
                                </option>
                            @endforeach
                        </select>
                        @error('type')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3 field">
                        <label for="contactValue" class="form-label">Контакт</label>
                        <input
                            id="contactValue"
                            type="text"
                            name="value"
                            class="form-control @error('value') is-invalid @enderror"
                            value="{{ old('value') }}"
                            required
                        >
                        @error('value')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3 field">
                        <label for="contactLabel" class="form-label">Пометка</label>
                        <input
                            id="contactLabel"
                            type="text"
                            name="label"
                            class="form-control @error('label') is-invalid @enderror"
                            value="{{ old('label') }}"
                            placeholder="Например: для связи, рабочий, личный"
                        >
                        @error('label')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn--primary btn--sm">Добавить</button>
                </form>
            </div>
        </div>

    @endif

@endsection
