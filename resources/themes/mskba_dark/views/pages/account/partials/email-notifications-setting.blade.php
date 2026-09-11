@php
    $notificationSetting = \App\Modules\Identity\Domain\Models\UserNotificationSetting::query()
        ->where('user_id', $user->canonical()->id)
        ->first();
    $selectedPreference = old(
        'email_notifications',
        $notificationSetting?->email_notifications?->value
            ?? \App\Modules\Identity\Domain\Enums\UserMessengerNotificationPreferenceEnum::ALL->value,
    );
@endphp

<fieldset class="account-privacy__rule">
    <legend>Уведомления по email</legend>
    <p class="account-privacy__description">
        Какие уведомления портала отправлять на подтверждённый основной email.
    </p>

    <label class="form-label" for="email-notifications">Отправлять</label>
    <select id="email-notifications" class="form-control" name="email_notifications">
        @foreach(\App\Modules\Identity\Domain\Enums\UserMessengerNotificationPreferenceEnum::cases() as $preference)
            <option value="{{ $preference->value }}" @selected($selectedPreference === $preference->value)>
                {{ $preference->label() }}
            </option>
        @endforeach
    </select>

    @error('email_notifications')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</fieldset>
