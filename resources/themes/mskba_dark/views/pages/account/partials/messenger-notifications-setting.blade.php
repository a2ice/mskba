@php
    $notificationSetting = \App\Modules\Identity\Domain\Models\UserNotificationSetting::query()
        ->where('user_id', $user->id)
        ->first();
    $selectedPreference = old(
        'messenger_notifications',
        $notificationSetting?->messenger_notifications->value
            ?? \App\Modules\Identity\Domain\Enums\UserMessengerNotificationPreferenceEnum::ALL->value,
    );
@endphp

<fieldset class="account-privacy__rule">
    <legend>Уведомления в Telegram</legend>
    <p class="account-privacy__description">
        Какие уведомления портала отправлять в Telegram при наличии подтверждённого контакта Telegram. Фактическая отправка будет подключена отдельно.
    </p>

    <label class="form-label" for="messenger-notifications">Отправлять</label>
    <select
        id="messenger-notifications"
        class="form-control"
        name="messenger_notifications"
    >
        @foreach(\App\Modules\Identity\Domain\Enums\UserMessengerNotificationPreferenceEnum::cases() as $preference)
            <option value="{{ $preference->value }}" @selected($selectedPreference === $preference->value)>
                {{ $preference->label() }}
            </option>
        @endforeach
    </select>

    @error('messenger_notifications')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</fieldset>
