@php
    use App\Modules\Identity\Domain\Enums\UserStatusEnum;

    $title = 'Подтверждение аккаунта';
    $currentRole = $currentParticipationRole ?? null;
    $selectedRoleValue = old('role', $currentRole?->value);
    $showRoleDetailsStep = in_array($selectedRoleValue, $rolesRequiringProfileDetails, true);
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])

@section('section-content')
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

    @if($user)
        @if($user->status === UserStatusEnum::CONFIRMED)
            <div class="alert alert-success">
                Аккаунт подтвержден.
            </div>
        @else
            <p class="mb-4 d-none">
                Заполните шаги wizard и отправьте форму в конце. Обязательные шаги отмечены как <span class="fw-bold">(О)</span>, шаги, которые можно пропустить, - как <span class="fw-bold">(Н)</span>.
            </p>

            <form
                method="POST"
                action="{{ route('account.confirmation.complete') }}"
                class="account-confirmation-wizard"
                data-account-confirmation-wizard
                data-existing-role="{{ $currentRole?->value }}"
                data-existing-role-label="{{ $currentRole?->label() }}"
                data-role-details-required="{{ implode(',', $rolesRequiringProfileDetails) }}"
            >
                @csrf

                <div class="account-confirmation-wizard__progress mb-4" aria-live="polite">
                    <span data-wizard-progress-current>1</span>
                    <span>/</span>
                    <span data-wizard-progress-total>1</span>
                </div>

                @unless($currentRole)
                    <section
                        class="account-confirmation-step"
                        data-wizard-step
                        data-step-key="participation_role"
                        data-required="true"
                    >
                        <div class="mb-3">
                            <h4 class="h5 mb-2 align-items-center d-flex">
                                <span class="badge badge--primary me-2" title="Обязательно к заполнению" data-tooltip-variant="title">*</span>
                                Выберите роль участия
                            </h4>
                            <p class="mb-0">Укажите, как вы участвуете в проекте: игрок, тренер, судья, представитель площадки или другая роль.</p>
                        </div>

                        <div class="field">
                            <label for="confirmationRole" class="form-label">Роль участия</label>
                            <select id="confirmationRole" name="role" class="form-select @error('role') is-invalid @enderror" required data-wizard-role>
                                <option value="">Выберите роль</option>
                                @foreach($participationRoles as $role)
                                    <option value="{{ $role->value }}" @selected(old('role') === $role->value)>
                                        {{ $role->label() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('role')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </section>
                @endunless

                @if(!$currentRole || $showRoleDetailsStep)
                    <section
                        class="account-confirmation-step"
                        data-wizard-step
                        data-step-key="birth_date"
                        data-required="true"
                        data-role-dependent="true"
                        @unless($showRoleDetailsStep) hidden @endunless
                    >
                        <div class="mb-3">
                            <h4 class="h5 mb-2 align-items-center d-flex">
                                <span class="badge badge--primary me-2" title="Обязательно к заполнению" data-tooltip-variant="title">*</span>
                                Заполните дату рождения
                            </h4>
                            <p class="mb-0">Для выбранной роли дата рождения нужна как часть базового профиля.</p>
                            <div class="account-confirmation-step-summary mt-3" data-wizard-summary hidden></div>
                        </div>

                        <div class="field">
                            <label for="confirmationBirthDate" class="form-label">Дата рождения</label>
                            <input
                                id="confirmationBirthDate"
                                type="date"
                                name="birth_date"
                                class="form-control @error('birth_date') is-invalid @enderror"
                                value="{{ old('birth_date', $user->profile?->birth_date?->format('Y-m-d')) }}"
                                data-role-required-field
                            >
                            @error('birth_date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </section>

                    <section
                        class="account-confirmation-step"
                        data-wizard-step
                        data-step-key="gender"
                        data-required="true"
                        data-role-dependent="true"
                        @unless($showRoleDetailsStep) hidden @endunless
                    >
                        <div class="mb-3">
                            <h4 class="h5 mb-2 align-items-center d-flex">
                                <span class="badge badge--primary me-2" title="Обязательно к заполнению" data-tooltip-variant="title">*</span>
                                Укажите пол
                            </h4>
                            <p class="mb-0">Для выбранной роли пол нужен как часть базового профиля.</p>
                            <div class="account-confirmation-step-summary mt-3" data-wizard-summary hidden></div>
                        </div>

                        <div class="field">
                            <label for="confirmationGender" class="form-label">Пол</label>
                            <select id="confirmationGender" name="gender" class="form-select @error('gender') is-invalid @enderror" data-role-required-field>
                                <option value="">Выберите пол</option>
                                @foreach($genders as $gender)
                                    <option value="{{ $gender->value }}" @selected(old('gender', $user->profile?->gender?->value) === $gender->value)>
                                        {{ $gender->label() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('gender')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </section>
                @endif

                <section
                    class="account-confirmation-step"
                    data-wizard-step
                    data-step-key="name"
                    data-required="false"
                >
                    <div class="mb-3">
                        <h4 class="h5 mb-2 align-items-center d-flex">
                            <span class="badge badge--primary me-2" title="Можно пропустить" data-tooltip-variant="title">*</span>
                            Представьтесь, пожалуйста
                        </h4>
                        <p class="mb-0">Можно указать имя и фамилию, чтобы профиль был понятен другим участникам проекта.</p>
                        <div class="account-confirmation-step-summary mt-3" data-wizard-summary hidden></div>
                    </div>

                    <div class="field">
                        <div class="mb-3">
                            <label for="confirmationFirstName" class="form-label">Имя</label>
                            <input
                                id="confirmationFirstName"
                                type="text"
                                name="first_name"
                                class="form-control @error('first_name') is-invalid @enderror"
                                value="{{ old('first_name', $user->profile?->first_name) }}"
                            >
                            @error('first_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="confirmationLastName" class="form-label">Фамилия</label>
                            <input
                                id="confirmationLastName"
                                type="text"
                                name="last_name"
                                class="form-control @error('last_name') is-invalid @enderror"
                                value="{{ old('last_name', $user->profile?->last_name) }}"
                            >
                            @error('last_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="confirmationMiddleName" class="form-label">Отчество</label>
                            <input
                                id="confirmationMiddleName"
                                type="text"
                                name="middle_name"
                                class="form-control @error('middle_name') is-invalid @enderror"
                                value="{{ old('middle_name', $user->profile?->middle_name) }}"
                            >
                            @error('middle_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </section>

                <div class="account-confirmation-wizard__actions mt-4">
                    <button type="button" class="btn btn--secondary btn--sm" data-wizard-back>Назад</button>
                    <button type="button" class="btn btn--primary btn--sm" data-wizard-next>Далее</button>
                    <button type="submit" class="btn btn--primary btn--sm" data-wizard-submit hidden>Подтвердить аккаунт</button>
                </div>
            </form>
        @endif
    @endif
@endsection
