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
    <a class="btn btn--secondary btn--sm" href="{{ route('account.sports-sections.edit', $section) }}">К управлению секцией</a>
@endsection

@section('section-content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->has('section'))<div class="alert alert-danger">{{ $errors->first('section') }}</div>@endif

<div class="sports-section-applications">
    <section class="sports-section-editor__panel">
        <h2>Публичный набор</h2>
        <p class="text-muted">«Принимает заявки» открывает обычную подачу заявок. «Идёт набор» усиливает этот статус в каталоге и всегда требует включённого приёма заявок.</p>
        <form method="POST" action="{{ route('account.sports-sections.applications.settings', $section) }}">
            @csrf @method('PATCH')
            @include('theme::partials.forms.toggle', [
                'id' => 'section-accepts-trainee-requests',
                'name' => 'accepts_trainee_requests',
                'title' => 'Принимать заявки в секцию',
                'description' => 'Игроки смогут отправлять заявку с публичной страницы секции.',
                'checked' => old('accepts_trainee_requests', $section->accepts_trainee_requests),
            ])
            @include('theme::partials.forms.toggle', [
                'id' => 'section-is-recruiting',
                'name' => 'is_recruiting',
                'title' => 'Идёт активный набор',
                'description' => 'Секция будет сильнее отмечена в каталоге. Если приём заявок выключен, он включится автоматически.',
                'checked' => old('is_recruiting', $section->is_recruiting),
            ])
            <button class="btn btn--primary" type="submit">Сохранить настройки</button>
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
