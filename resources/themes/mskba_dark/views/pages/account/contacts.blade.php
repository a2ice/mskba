@extends('theme::layouts.account', [
    'title' => 'Мои контакты',
])

@section('account-content')

    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

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
                        <li class="account-contact-item mb-3">
                            <div>
                                <span class="fw-bold">{{ $contact->type->label() }}:</span>
                                <span>{{ $contact->value }}</span>
                                @if($contact->label)
                                    <span class="text-muted">({{ $contact->label }})</span>
                                @endif
                            </div>
                            <div class="account-contact-item__meta">
                                @if($contact->is_primary)
                                    <span>Основной</span>
                                @endif
                                @if($contact->is_public)
                                    <span>Публичный</span>
                                @endif
                                <span>{{ $contact->isVerified() ? 'Подтвержден' : 'Не подтвержден' }}</span>
                            </div>
                            @if(! $contact->isVerified() && $contact->type === \App\Modules\Contact\Domain\Enums\ContactTypeEnum::EMAIL)
                                <form method="POST" action="{{ route('account.contacts.verification.store', $contact) }}" class="mt-2">
                                    @csrf
                                    <button type="submit" class="btn btn--secondary btn--sm">Подтвердить</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mb-0">Контакты пока не добавлены.</p>
            @endif
        </div>

        <div class="account-contacts__form">
            <h2 class="h3 mb-3">Добавить контакт</h2>

            <form method="POST" action="{{ route('account.contacts.store') }}">
                @csrf

                <div class="mb-3">
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

                <div class="mb-3">
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

                <div class="mb-3">
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

@endsection
