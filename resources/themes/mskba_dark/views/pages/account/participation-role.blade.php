@php
    use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
@endphp

@php $title = $title ?? 'Роль в проекте'; @endphp
@php
    $breadcrumbs = isset($role) ? [
        ['label' => 'Аккаунт', 'url' => route('account')],
        ['label' => 'Роли в проекте', 'url' => route('account.roles')],
        ['label' => $role->label()],
    ] : null;
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
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

    @if(isset($participationRole))
        <div class="mb-4">
            <h3 class="h3 mb-3">{{ $role->label() }}</h3>

            <ul class="list-unstyled mb-0">
                <li class="list-unstyled mb-2">
                    Статус:
                    <span class="fw-bold">{{ $participationRole->status->label() }}</span>
                </li>
                <li class="list-unstyled mb-2">
                    Назначена:
                    <span class="fw-bold">{{ $participationRole->assigned_at?->format('d.m.Y H:i') ?? 'не указано' }}</span>
                </li>
                <li class="list-unstyled mb-2">
                    Источник:
                    <span class="fw-bold">{{ $participationRole->assigner?->label() ?? 'не указан' }}</span>
                </li>
                @if($participationRole->expires_at)
                    <li class="list-unstyled mb-2">
                        Действует до:
                        <span class="fw-bold">{{ $participationRole->expires_at->format('d.m.Y H:i') }}</span>
                    </li>
                @endif
                @if($participationRole->comment)
                    <li class="list-unstyled mb-2">
                        Комментарий:
                        <span class="fw-bold">{{ $participationRole->comment }}</span>
                    </li>
                @endif
            </ul>
        </div>

        @if($role === UserParticipationRoleEnum::PLAYER)
            @php
                $profile = $user->playerProfile;
                $selectedPositions = old(
                    'positions',
                    $profile?->positions->map(fn ($item) => $item->position->value)->all() ?? [],
                );
            @endphp

            <form
                method="POST"
                action="{{ route('account.player-profile.update') }}"
                class="account-player-profile"
            >
                @csrf
                @method('PATCH')

                <section class="account-player-profile__section">
                    <div>
                        <h3 class="h4 mb-1">Характеристики игрока</h3>
                        <p class="text-muted mb-0">Основные физические данные и баскетбольная специализация.</p>
                    </div>

                    <div class="account-player-profile__grid">
                        <div class="form-group field account-player-profile__field">
                            <label for="player-height">Рост, см</label>
                            <select
                                id="player-height"
                                class="form-select"
                                name="height_cm"
                            >
                                <option value="">Не указан</option>
                                @for($height = 150; $height <= 220; $height++)
                                    <option
                                        value="{{ $height }}"
                                        @selected((string) old('height_cm', $profile?->height_cm) === (string) $height)
                                    >{{ $height }} см</option>
                                @endfor
                            </select>
                            @error('height_cm') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        </div>

                        <div class="form-group field account-player-profile__field">
                            <label for="player-weight">Вес, кг</label>
                            @php $currentWeight = old('weight_kg', $profile?->weight_kg); @endphp
                            <select
                                id="player-weight"
                                class="form-select"
                                name="weight_kg"
                            >
                                <option value="">Не указан</option>
                                @for($weight = 40; $weight <= 140; $weight++)
                                    <option
                                        value="{{ $weight }}"
                                        @selected((string) $currentWeight === (string) $weight)
                                    >{{ $weight }} кг</option>
                                @endfor
                            </select>
                            @error('weight_kg') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        </div>

                        <div class="form-group field account-player-profile__field">
                            <label for="player-body-type">Сложение</label>
                            <select id="player-body-type" class="form-select" name="body_type">
                                <option value="">Не указано</option>
                                @foreach($playerBodyTypes as $bodyType)
                                    <option
                                        value="{{ $bodyType->value }}"
                                        @selected(old('body_type', $profile?->body_type?->value) === $bodyType->value)
                                    >{{ $bodyType->label() }}</option>
                                @endforeach
                            </select>
                            @error('body_type') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        </div>

                        <div class="form-group field account-player-profile__field">
                            <label for="player-experience-year">Играю с</label>
                            <select
                                id="player-experience-year"
                                class="form-select"
                                name="experience_started_year"
                            >
                                <option value="">Не указано</option>
                                @for($year = now()->year - 10; $year >= now()->year - 50; $year--)
                                    <option
                                        value="{{ $year }}"
                                        @selected((string) old('experience_started_year', $profile?->experience_started_year) === (string) $year)
                                    >{{ $year }}</option>
                                @endfor
                            </select>
                            @error('experience_started_year') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <fieldset class="account-player-profile__positions">
                        <legend>Амплуа</legend>
                        <p class="text-muted">Можно выбрать несколько позиций.</p>
                        <div class="account-player-profile__position-grid">
                            @foreach($playerPositions as $position)
                                @include('theme::partials.forms.toggle', [
                                    'id' => 'player-position-'.$position->value,
                                    'name' => 'positions[]',
                                    'checked' => in_array($position->value, $selectedPositions, true),
                                    'title' => $position->label(),
                                    'wrapperClass' => 'account-player-profile__position',
                                    'value' => $position->value,
                                    'includeHiddenInput' => false,
                                ])
                            @endforeach
                        </div>
                        @error('positions') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        @error('positions.*') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </fieldset>
                </section>

                <section class="account-player-profile__section">
                    <div>
                        <h3 class="h4 mb-1">Самооценка игровых навыков</h3>
                        <p class="text-muted mb-0">
                            Оцените себя от 1 до 10. Позже рядом появятся отдельные объективные показатели,
                            рассчитанные по статистике сыгранных матчей.
                        </p>
                    </div>

                    <div class="account-player-profile__skills">
                        @foreach($playerSkills as $skill => $label)
                            <div class="form-group field account-player-profile__field">
                                <label for="player-skill-{{ $skill }}">{{ $label }}</label>
                                <select
                                    id="player-skill-{{ $skill }}"
                                    class="form-select"
                                    name="self_assessment[{{ $skill }}]"
                                >
                                    <option value="">Не оценено</option>
                                    @for($score = 1; $score <= 10; $score++)
                                        <option
                                            value="{{ $score }}"
                                            @selected((string) old('self_assessment.'.$skill, $profile?->selfAssessment?->{$skill}) === (string) $score)
                                        >{{ $score }}</option>
                                    @endfor
                                </select>
                                @error('self_assessment.'.$skill)
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="account-player-profile__section">
                    <div class="form-group field account-player-profile__field account-player-profile__field--wide">
                        <label for="player-comment">О себе как об игроке</label>
                        <textarea
                            id="player-comment"
                            class="form-control"
                            name="comment"
                            rows="4"
                            maxlength="1000"
                        >{{ old('comment', $profile?->comment) }}</textarea>
                        @error('comment') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                </section>

                <div class="account-player-profile__actions">
                    <button type="submit" name="redirect_to" value="role" class="btn btn--primary btn--xs">
                        Сохранить
                    </button>
                    <button type="submit" name="redirect_to" value="account" class="btn btn--secondary btn--xs">
                        Сохранить и закрыть
                    </button>
                </div>
            </form>
        @else
            <p class="mb-0">Детальная карточка этой роли будет расширена позже.</p>
        @endif
    @endif
@endsection
