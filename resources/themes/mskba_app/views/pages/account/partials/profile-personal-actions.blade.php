<button type="submit" class="button primary" data-inline-profile-save-all
    @if ($profilePersonalInDialog ?? false) form="app-player-personal-form" @endif disabled>Применить</button>
@if ($profileConfirmed && $activeProfileChangeRequest === null)
    <button type="button" class="button secondary" data-profile-change-request-open>Запросить изменение подтверждённых данных</button>
@endif
