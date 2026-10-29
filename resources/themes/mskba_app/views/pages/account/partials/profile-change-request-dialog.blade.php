    @if ($profileConfirmed && $activeProfileChangeRequest === null)
        <dialog class="mskba-modal app-profile__request-dialog" data-profile-change-request-dialog
                aria-labelledby="app-profile-request-heading">
            <div class="mskba-modal__header app-profile__request-header">
                <h2 id="app-profile-request-heading" tabindex="-1">Изменение данных</h2>
                <button type="button" class="app-profile__dialog-close" data-profile-change-request-close aria-label="Закрыть">×</button>
            </div>
            <div class="mskba-modal__body mskba-scroll app-profile__request-body">
                <p>Укажи исправленные данные и причину. Пока модератор не одобрит заявку, профиль останется без изменений.</p>
                <form method="POST" action="{{ route('account.profile-change-requests.store') }}" id="profile-change-request-form" class="app-profile__personal-form">
                    @csrf
                    <div class="app-profile__field-grid">
                        <div class="field-group">
                            <label for="profile-change-first">Имя</label>
                            <input id="profile-change-first" name="first_name" required maxlength="255" value="{{ old('first_name', $profile?->first_name) }}">
                        </div>
                        <div class="field-group">
                            <label for="profile-change-last">Фамилия</label>
                            <input id="profile-change-last" name="last_name" required maxlength="255" value="{{ old('last_name', $profile?->last_name) }}">
                        </div>
                        <div class="field-group">
                            <label for="profile-change-birth">Дата рождения</label>
                            <input id="profile-change-birth" type="date" name="birth_date" required max="{{ now()->subDay()->toDateString() }}"
                                   value="{{ old('birth_date', $profile?->birth_date?->toDateString()) }}">
                        </div>
                        <div class="field-group">
                            <label for="profile-change-gender">Пол</label>
                            <span class="select-control">
                                <select id="profile-change-gender" name="gender" required>
                                    @foreach ($genders as $gender)
                                        <option value="{{ $gender->value }}" @selected(old('gender', $profile?->gender?->value) === $gender->value)>{{ $gender->label() }}</option>
                                    @endforeach
                                </select>
                                <svg class="app-confirmation__select-arrow" aria-hidden="true"><use href="#chevron-down"/></svg>
                            </span>
                        </div>
                    </div>
                    <div class="field-group">
                        <label for="profile-change-reason">Причина изменения</label>
                        <textarea id="profile-change-reason" name="reason" required minlength="10" maxlength="2000" rows="3">{{ old('reason') }}</textarea>
                        @error('reason') <small class="app-profile__error">{{ $message }}</small> @enderror
                    </div>
                </form>
            </div>
            <div class="mskba-modal__footer app-profile__request-footer">
                <button class="button secondary" type="button" data-profile-change-request-close>Отмена</button>
                <button type="submit" form="profile-change-request-form" class="button primary">Отправить заявку</button>
            </div>
        </dialog>
    @endif
