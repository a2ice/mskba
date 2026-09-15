@php
    $targetYear = $section->getAttribute('target_year');
    $targetYearFrom = $section->getAttribute('target_year_from');
    $targetYearTo = $section->getAttribute('target_year_to');
    $audienceMode = old('audience_mode');
    if ($audienceMode === null) {
        $audienceMode = $targetYear !== null ? 'exact' : (($targetYearFrom !== null || $targetYearTo !== null) ? 'range' : 'none');
    }
@endphp
@extends('theme::layouts.section-sidebar', [
    'title' => 'Заявки · '.$section->name,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => 'Заявки и набор · '.$section->name,
    'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])

@section('section-heading-action')
    <a class="btn btn--secondary btn--sm" href="{{ route('sports-sections.show', $section) }}">Открыть секцию</a>
    <a class="btn btn--secondary btn--sm" href="{{ route('account.sports-sections.edit', $section) }}">К управлению</a>
@endsection

@section('section-content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

<div class="sports-section-applications">
    <section class="sports-section-editor__panel">
        <h2>Публичный набор</h2>
        <p class="text-muted">Сначала включается приём заявок. Активный набор — зависимый режим: он доступен только при открытом приёме заявок и усиливает секцию в каталоге.</p>
        <form method="POST" action="{{ route('account.sports-sections.applications.settings', $section) }}" data-sports-section-recruitment-settings data-sports-section-audience>
            @csrf @method('PATCH')
            @include('theme::partials.forms.toggle', [
                'id' => 'section-accepts-trainee-requests',
                'name' => 'accepts_trainee_requests',
                'title' => 'Принимать заявки в секцию',
                'description' => 'Игроки смогут отправлять заявку с публичной страницы секции.',
                'checked' => old('accepts_trainee_requests', $section->accepts_trainee_requests),
                'inputAttributes' => ['data-section-accepts-requests' => true],
            ])
            <div class="sports-section-recruitment-settings__dependent" data-section-recruiting-dependent>
                @include('theme::partials.forms.toggle', [
                    'id' => 'section-is-recruiting',
                    'name' => 'is_recruiting',
                    'title' => 'Идёт активный набор',
                    'description' => 'Дополнительно выделяет секцию в каталоге и показывает, что набор сейчас приоритетный.',
                    'checked' => old('is_recruiting', $section->is_recruiting),
                    'inputAttributes' => ['data-section-recruiting' => true],
                ])
            </div>

            <label class="form-field mt-3">
                <span>Количество мест в секции</span>
                <input class="form-control" type="number" min="1" max="1000" name="trainee_capacity" value="{{ old('trainee_capacity', $section->trainee_capacity) }}" placeholder="Без ограничения">
                <small class="text-muted">Необязательно. На публичной странице и в каталоге будет показано количество подтверждённых участников относительно лимита, например 1/15.</small>
            </label>

            <fieldset class="sports-section-age-fieldset mt-3">
                <legend>Год рождения</legend>
                <p class="text-muted mb-0">Необязательно. Можно указать один год рождения или полный диапазон.</p>
                <div class="sports-section-age-fieldset__modes">
                    <label><input type="radio" name="audience_mode" value="none" @checked($audienceMode === 'none') data-section-audience-mode> Без ограничения</label>
                    <label><input type="radio" name="audience_mode" value="exact" @checked($audienceMode === 'exact') data-section-audience-mode> Точный год</label>
                    <label><input type="radio" name="audience_mode" value="range" @checked($audienceMode === 'range') data-section-audience-mode> Диапазон</label>
                </div>
                <div class="sports-section-age-fieldset__values" data-section-audience-exact>
                    <label class="form-field"><span>Год рождения</span><input class="form-control" type="number" min="1900" max="{{ now()->year }}" name="target_year" value="{{ old('target_year', $targetYear) }}"></label>
                </div>
                <div class="sports-section-age-fieldset__values" data-section-audience-range>
                    <label class="form-field"><span>От</span><input class="form-control" type="number" min="1900" max="{{ now()->year }}" name="target_year_from" value="{{ old('target_year_from', $targetYearFrom) }}"></label>
                    <label class="form-field"><span>До</span><input class="form-control" type="number" min="1900" max="{{ now()->year }}" name="target_year_to" value="{{ old('target_year_to', $targetYearTo) }}"></label>
                </div>
            </fieldset>

            <button class="btn btn--primary mt-3" type="submit">Сохранить настройки</button>
        </form>
    </section>

    <section class="sports-section-editor__panel">
        <div class="sports-section-editor__panel-heading">
            <div><h2>Заявки</h2><p class="text-muted">Принятая заявка создаёт или реактивирует постоянное участие игрока в секции.</p></div>
        </div>
        <div class="sports-section-editor__items sports-section-applications__list">
            @forelse($joinRequests as $application)
                @php
                    $profile = $application->user?->profile;
                    $name = trim(($profile?->first_name ?? '').' '.($profile?->last_name ?? '')) ?: ($application->user?->username ?? 'Пользователь #'.$application->user_id);
                @endphp
                <article class="sports-section-application-card">
                    <div class="sports-section-editor__person">
                        <strong>{{ $name }}</strong>
                        <span class="text-muted">{{ $application->status->label() }} · {{ $application->created_at->format('d.m.Y H:i') }}</span>
                        @if($application->review_reason)<small>{{ $application->review_reason }}</small>@endif
                    </div>
                    @if($application->status === \App\Modules\SportsSection\Domain\Enums\SportsSectionJoinRequestStatusEnum::PENDING)
                        <div class="sports-section-editor__actions">
                            <form method="POST" action="{{ route('account.sports-sections.applications.respond', [$section, $application]) }}">
                                @csrf @method('PATCH')
                                <input type="hidden" name="action" value="accept">
                                <button class="btn btn--primary btn--sm" type="submit">Принять</button>
                            </form>
                            <form method="POST" action="{{ route('account.sports-sections.applications.respond', [$section, $application]) }}" class="sports-section-application-card__reject">
                                @csrf @method('PATCH')
                                <input type="hidden" name="action" value="reject">
                                <input class="form-control" name="review_reason" maxlength="2000" placeholder="Причина, необязательно">
                                <button class="btn btn--secondary btn--sm" type="submit">Отклонить</button>
                            </form>
                        </div>
                    @endif
                </article>
            @empty
                <div class="alert alert-info">Заявок пока нет.</div>
            @endforelse
        </div>
    </section>
</div>
@endsection
