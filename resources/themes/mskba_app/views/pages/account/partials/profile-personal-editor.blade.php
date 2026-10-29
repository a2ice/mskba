        <section class="panel app-profile__panel" aria-labelledby="app-profile-personal-heading">
            <h2 id="app-profile-personal-heading">Личные данные</h2>
            @if ($profileConfirmed)
                <p class="app-profile__support-copy">Твои имя, фамилия, дата рождения и пол уже подтверждены. Для их изменения нужно отправить заявку.</p>
            @endif
            @php
                $personalFields = [
                    ['name' => 'first_name', 'id' => 'app-profile-first-name', 'label' => 'Имя', 'type' => 'text',
                     'auto' => 'given-name', 'value' => (string) ($profile?->first_name ?? '')],
                    ['name' => 'last_name', 'id' => 'app-profile-last-name', 'label' => 'Фамилия', 'type' => 'text',
                     'auto' => 'family-name', 'value' => (string) ($profile?->last_name ?? '')],
                    ['name' => 'middle_name', 'id' => 'app-profile-middle-name', 'label' => 'Отчество', 'type' => 'text',
                     'auto' => 'additional-name', 'value' => (string) ($profile?->middle_name ?? '')],
                    ['name' => 'birth_date', 'id' => 'app-profile-birth-date', 'label' => 'Дата рождения', 'type' => 'date',
                     'auto' => 'bday', 'value' => $profile?->birth_date?->toDateString() ?? ''],
                    ['name' => 'gender', 'id' => 'app-profile-gender', 'label' => 'Пол', 'type' => 'select',
                     'auto' => 'sex', 'value' => $profile?->gender?->value ?? ''],
                ];
            @endphp
            <form method="POST" action="{{ route('account.profile.update') }}"
                  id="{{ ($profilePersonalInDialog ?? false) ? 'app-player-personal-form' : 'app-profile-personal-form' }}"
                  class="app-profile__personal-form" data-profile-personal-form
                  data-save-url="{{ route('account.profile.update') }}">
                @csrf
                @method('PATCH')
                <div class="app-profile__field-grid">
                    @foreach ($personalFields as $field)
                        @php($locked = $profileConfirmed && $field['name'] !== 'middle_name')
                        <div class="field-group" @unless($locked)
                             data-inline-profile-field data-field-name="{{ $field['name'] }}"
                             data-saved-value="{{ $field['value'] }}"
                             @endunless>
                            <label for="{{ $field['id'] }}">{{ $field['label'] }}</label>
                            <div class="app-confirmation__input-row">
                                <span class="app-confirmation__input-wrap {{ $field['type'] === 'select' ? 'select-control' : '' }} {{ $field['type'] === 'date' ? 'app-confirmation__input-wrap--date' : '' }} {{ $locked ? 'app-profile__locked-wrap' : '' }}">
                                    @if ($field['type'] === 'select')
                                        <select id="{{ $field['id'] }}" autocomplete="{{ $field['auto'] }}"
                                                @unless($locked) name="{{ $field['name'] }}" data-inline-profile-input @endunless
                                                @disabled($locked)>
                                            <option value="">Не указан</option>
                                            @foreach ($genders as $gender)
                                                <option value="{{ $gender->value }}" @selected(old($field['name'], $field['value']) === $gender->value)>{{ $gender->label() }}</option>
                                            @endforeach
                                        </select>
                                        <svg class="app-confirmation__select-arrow" aria-hidden="true"><use href="#chevron-down"/></svg>
                                    @else
                                        <input id="{{ $field['id'] }}" type="{{ $field['type'] }}"
                                               autocomplete="{{ $field['auto'] }}"
                                               @if ($field['type'] === 'text') maxlength="255" @endif
                                               @if ($field['type'] === 'date') max="{{ now()->subDay()->toDateString() }}" @endif
                                               @unless($locked) name="{{ $field['name'] }}" data-inline-profile-input @endunless
                                               value="{{ $locked ? $field['value'] : old($field['name'], $field['value']) }}"
                                               @readonly($locked)>
                                    @endif
                                    @unless($locked)
                                        <button type="button" class="app-confirmation__save-field"
                                                data-inline-profile-save
                                                aria-label="Сохранить {{ mb_strtolower($field['label']) }}"
                                                title="Сохранить {{ mb_strtolower($field['label']) }}"
                                                disabled>
                                            <svg class="app-confirmation__save-icon" aria-hidden="true"><use href="#save"/></svg>
                                            <span class="app-confirmation__save-loader" data-inline-profile-loading aria-hidden="true" hidden></span>
                                            <span class="app-confirmation__save-cooldown" data-inline-profile-cooldown aria-hidden="true" hidden></span>
                                        </button>
                                    @endunless
                                </span>
                            </div>
                            @unless($locked)
                                <small class="app-profile__error" data-inline-profile-error role="alert" hidden></small>
                            @endunless
                            @error($field['name']) <small class="app-profile__error">{{ $message }}</small> @enderror
                        </div>
                    @endforeach
                </div>
                @unless ($profilePersonalInDialog ?? false)
                    <div class="app-profile__form-actions">
                        @include('theme::pages.account.partials.profile-personal-actions')
                        <p class="app-profile__save-feedback" data-inline-profile-feedback role="status" aria-live="polite"></p>
                    </div>
                @else
                    <p class="app-profile__save-feedback" data-inline-profile-feedback role="status" aria-live="polite"></p>
                @endunless
            </form>
        </section>

    @if ($profileConfirmed && $latestProfileChangeRequest)
        <section class="panel app-profile__panel" aria-labelledby="profile-request-status">
            <h2 id="profile-request-status">Заявка на изменение данных №{{ $latestProfileChangeRequest->id }}</h2>
            <p class="app-profile__support-copy">
                Статус: <strong>{{ [
                    'pending' => 'На рассмотрении',
                    'approved' => 'Одобрена',
                    'rejected' => 'Отклонена',
                    'cancelled' => 'Отменена',
                    'blocked' => 'Заблокирована',
                ][$latestProfileChangeRequest->status] ?? $latestProfileChangeRequest->status }}</strong>.
                @if ($latestProfileChangeRequest->review_reason)
                    Причина решения: {{ $latestProfileChangeRequest->review_reason }}
                @endif
                @if ($latestProfileChangeRequest->status === 'blocked')
                    Повторная подача недоступна до разблокировки администратором.
                @elseif ($latestProfileChangeRequest->status === 'rejected')
                    Ты можешь отправить новую заявку.
                @endif
            </p>
            <a class="button secondary" href="{{ route('account.notifications.threads.profile', $latestProfileChangeRequest) }}">Открыть обсуждение</a>
            @if ($latestProfileChangeRequest->status === 'pending')
                <form method="POST" action="{{ route('account.profile-change-requests.cancel', $latestProfileChangeRequest) }}">
                    @csrf
                    <button type="submit" class="button secondary">Отменить заявку</button>
                </form>
            @endif
        </section>
    @endif
