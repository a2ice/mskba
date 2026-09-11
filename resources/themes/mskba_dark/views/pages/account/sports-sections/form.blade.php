@php($editing = $section !== null)
@extends('theme::layouts.section-sidebar', [
    'title' => $editing ? 'Секция · '.$section->name : 'Новая секция', 'sectionId' => 'account', 'sectionClass' => 'account-section',
    'contentTitle' => $editing ? $section->name : 'Новая секция', 'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false, 'sidebarPartial' => 'theme::partials.account.sidebar',
])

@section('section-content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

<div class="sports-section-editor">
@if($editing)
<nav class="sports-section-editor__nav" aria-label="Разделы секции">
    <a href="#section-main">Основное</a>
    <a href="#section-media">Фотографии</a>
    <a href="#section-coaches">Тренеры</a>
    <a href="#section-trainees">Занимающиеся</a>
    <a href="#section-pricing">Тарифы</a>
    <a href="#section-contacts">Контакты</a>
    <a href="#section-sessions">Занятия</a>
</nav>
@endif

<section class="sports-section-editor__panel" id="section-main"><h2>Основное</h2>
<form method="POST" action="{{ $editing ? route('account.sports-sections.update', $section) : route('account.sports-sections.store') }}" class="sports-section-form" data-venue-court-group>@csrf @if($editing)@method('PUT')@endif
    <label class="form-field"><span>Название</span><input class="form-control" name="name" required value="{{ old('name', $section?->name) }}"></label>
    <label class="form-field sports-section-form__wide"><span>Описание</span><textarea class="form-control" name="description" rows="4">{{ old('description', $section?->description) }}</textarea></label>
    @if($editing)<label class="form-field"><span>Статус</span><select class="form-select" name="status">@foreach($statuses as $item)<option value="{{ $item->value }}" @selected(old('status', $section->status->value) === $item->value)>{{ $item->label() }}</option>@endforeach</select></label>@endif
    <label class="form-field"><span>Формат тренировок</span><select class="form-select" name="training_mode">@foreach($trainingModes as $item)<option value="{{ $item->value }}" @selected(old('training_mode', $section?->training_mode?->value) === $item->value)>{{ $item->label() }}</option>@endforeach</select></label>
    <label class="form-field"><span>Игровой формат</span><select class="form-select" name="game_format">@foreach($formats as $item)<option value="{{ $item->value }}" @selected(old('game_format', $section?->game_format?->value ?? 'basketball_5x5') === $item->value)>{{ $item->label() }}</option>@endforeach</select></label>
    <label class="form-field"><span>Основная площадка</span><select class="form-select" name="primary_venue_id" data-venue-select><option value="">Не указана</option>@foreach($venues as $venue)<option value="{{ $venue->id }}" @selected((string)old('primary_venue_id', $section?->primary_venue_id) === (string)$venue->id)>{{ $venue->name }}</option>@endforeach</select></label>
    <label class="form-field"><span>Основной зал</span><select class="form-select" name="primary_venue_court_id" data-court-select><option value="">Не указан</option>@foreach($venues as $venue)@foreach($venue->courts as $court)<option value="{{ $court->id }}" data-venue-id="{{ $venue->id }}" @selected((string)old('primary_venue_court_id', $section?->primary_venue_court_id) === (string)$court->id)>{{ $court->name }}</option>@endforeach @endforeach</select></label>
    <label class="form-field"><span>Оплата</span><select class="form-select" name="pricing_type" data-pricing-type>@foreach($pricingTypes as $item)<option value="{{ $item->value }}" @selected(old('pricing_type', $section?->pricing_type?->value ?? 'free') === $item->value)>{{ $item->label() }}</option>@endforeach</select></label>
    <label class="form-field" data-price-field><span>Стоимость одного занятия, руб.</span><input class="form-control" type="number" min="0.01" step="0.01" name="single_session_price" value="{{ old('single_session_price', $section?->single_session_price_minor !== null ? $section->single_session_price_minor / 100 : '') }}"></label>
    <input type="hidden" name="currency" value="RUB">
    <label class="form-field"><span>Показывать контакты</span><select class="form-select" name="contact_source">@foreach($contactSources as $item)<option value="{{ $item->value }}" @selected(old('contact_source', $section?->contact_source?->value ?? 'head_coach') === $item->value)>{{ $item->label() }}</option>@endforeach</select></label>
    <label class="form-field"><span>Комментарий к записи</span><input class="form-control" name="contact_notes" placeholder="Например: напишите в Telegram перед первым занятием" value="{{ old('contact_notes', $section?->contact_notes) }}"></label>
    <div class="sports-section-form__wide"><button class="btn btn--primary" type="submit">{{ $editing ? 'Сохранить основные данные' : 'Создать секцию' }}</button></div>
</form></section>

@if($editing)
<section class="sports-section-editor__panel" id="section-media"><div class="sports-section-editor__panel-heading"><div><h2>Фотографии</h2><p class="text-muted">Основная фотография используется в каталоге и в шапке публичной страницы.</p></div></div><div class="sports-section-editor__gallery">@foreach($section->media as $photo)<article><img src="{{ $photo->publicUrl() }}" alt=""><div>@if(!$photo->is_featured)<form method="POST" action="{{ route('account.sports-sections.photos.feature', [$section, $photo->id]) }}">@csrf @method('PATCH')<button class="btn btn--secondary btn--sm">Сделать основной</button></form>@else<span class="text-accent">Основная</span>@endif<form method="POST" action="{{ route('account.sports-sections.photos.destroy', [$section, $photo->id]) }}">@csrf @method('DELETE')<button class="btn btn--secondary btn--sm">Удалить</button></form></div></article>@endforeach</div><form method="POST" enctype="multipart/form-data" action="{{ route('account.sports-sections.photos.store', $section) }}" class="sports-section-editor__inline">@csrf<input class="form-control" type="file" name="photo" accept="image/jpeg,image/png,image/webp" required><button class="btn btn--primary btn--sm">Добавить фото</button></form></section>

<section class="sports-section-editor__panel" id="section-coaches"><h2>Тренеры</h2><div class="sports-section-editor__items">@foreach($section->coachMemberships as $membership)@php($name = trim(($membership->user?->profile?->first_name ?? '').' '.($membership->user?->profile?->last_name ?? '')) ?: $membership->user?->username)<article><div class="sports-section-editor__person"><strong>{{ $name }}</strong><span class="text-muted">{{ $section->head_coach_membership_id === $membership->id ? 'Главный тренер' : 'Тренер' }}</span></div>@if($membership->access_level !== 'owner')<details><summary>Права управления</summary><form method="POST" action="{{ route('account.sports-sections.coaches.permissions', [$section, $membership]) }}" class="sports-section-editor__permissions">@csrf @method('PUT')@foreach(\App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum::cases() as $permission)<label><input type="checkbox" name="permissions[]" value="{{ $permission->value }}" @checked($membership->contract->permissions->contains('permission', $permission->value))> {{ $permission->label() }}</label>@endforeach<button class="btn btn--secondary btn--sm">Сохранить права</button></form></details>@endif @if($section->head_coach_membership_id !== $membership->id)<div class="sports-section-editor__actions"><form method="POST" action="{{ route('account.sports-sections.coaches.head-coach', [$section, $membership]) }}">@csrf @method('PATCH')<button class="btn btn--secondary btn--sm">Назначить главным</button></form><form method="POST" action="{{ route('account.sports-sections.coaches.destroy', [$section, $membership]) }}">@csrf @method('DELETE')<button class="btn btn--secondary btn--sm">Отключить</button></form></div>@endif</article>@endforeach</div><form method="POST" action="{{ route('account.sports-sections.coaches.store', $section) }}" class="sports-section-editor__inline">@csrf @include('theme::partials.forms.entity-predictive-search', ['id' => 'sportsSectionCoach', 'name' => 'user_id', 'label' => 'Добавить тренера', 'placeholder' => 'Имя или логин…', 'searchUrl' => route('account.sports-sections.candidates', [$section, 'role' => 'coach'])])<button class="btn btn--primary btn--sm">Добавить тренера</button></form></section>

<section class="sports-section-editor__panel" id="section-trainees"><h2>Занимающиеся</h2><p class="text-muted">Это постоянный состав секции. При создании занятия текущие активные участники копируются в его состав.</p><div class="sports-section-editor__items">@foreach($section->traineeMemberships as $membership)@php($traineeName = trim(($membership->user?->profile?->first_name ?? '').' '.($membership->user?->profile?->last_name ?? '')) ?: $membership->user?->username)<article><div class="sports-section-editor__person"><strong>{{ $traineeName }}</strong><span class="text-muted">{{ $membership->status->label() }}</span></div>@if($membership->status->value === 'active')<form method="POST" action="{{ route('account.sports-sections.trainees.deactivate', [$section, $membership]) }}" class="sports-section-editor__actions">@csrf @method('PATCH')<input class="form-control" name="reason" placeholder="Причина, необязательно"><button class="btn btn--secondary btn--sm">Сделать неактивным</button></form>@endif</article>@endforeach</div><form method="POST" action="{{ route('account.sports-sections.trainees.store', $section) }}" class="sports-section-editor__inline">@csrf @include('theme::partials.forms.entity-predictive-search', ['id' => 'sportsSectionPlayer', 'name' => 'user_id', 'label' => 'Добавить игрока', 'placeholder' => 'Имя или логин…', 'searchUrl' => route('account.sports-sections.candidates', [$section, 'role' => 'player'])])<button class="btn btn--primary btn--sm">Добавить</button></form></section>

<section class="sports-section-editor__panel" id="section-pricing"><h2>Тарифы</h2><div class="sports-section-editor__items">@foreach($section->pricingPlans as $plan)<article><div><strong>{{ $plan->name }}</strong><span class="text-muted">{{ number_format($plan->amount_minor / 100, 0, ',', ' ') }} руб.{{ $plan->sessions_count ? ' · '.$plan->sessions_count.' занятий' : '' }}{{ $plan->duration_days ? ' · '.$plan->duration_days.' дней' : '' }}</span></div><form method="POST" action="{{ route('account.sports-sections.plans.toggle', [$section, $plan]) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $plan->is_active ? 0 : 1 }}"><button class="btn btn--secondary btn--sm">{{ $plan->is_active ? 'Выключить' : 'Включить' }}</button></form></article>@endforeach</div><form method="POST" action="{{ route('account.sports-sections.plans.store', $section) }}" class="sports-section-editor__inline">@csrf<input class="form-control" name="name" placeholder="Название тарифа" required><input class="form-control" name="amount" type="number" min="0.01" step="0.01" placeholder="Цена, руб." required><input class="form-control" name="sessions_count" type="number" min="1" placeholder="Количество занятий"><input class="form-control" name="duration_days" type="number" min="1" placeholder="Срок, дней"><button class="btn btn--primary btn--sm">Добавить тариф</button></form></section>

<section class="sports-section-editor__panel" id="section-contacts"><h2>Контакты секции</h2><div class="sports-section-editor__items">@foreach($section->contacts as $contact)<article><div><strong>{{ $contact->type->label() }}</strong><span class="text-muted">{{ $contact->displayValue() }}</span></div><form method="POST" action="{{ route('account.sports-sections.contacts.destroy', [$section, $contact]) }}">@csrf @method('DELETE')<button class="btn btn--secondary btn--sm">Удалить</button></form></article>@endforeach</div><form method="POST" action="{{ route('account.sports-sections.contacts.store', $section) }}" class="sports-section-editor__inline">@csrf<select class="form-select" name="type">@foreach(\App\Modules\Contact\Domain\Enums\ContactTypeEnum::cases() as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach</select><input class="form-control" name="value" required placeholder="Значение"><input class="form-control" name="label" placeholder="Подпись"><button class="btn btn--primary btn--sm">Добавить</button></form></section>

<section class="sports-section-editor__panel" id="section-sessions"><div class="sports-section-editor__panel-heading"><div><h2>Занятия</h2><p class="text-muted">Создай конкретное занятие, при необходимости скорректируй его состав и затем опубликуй на MSKBA.</p></div></div>
    <form method="POST" action="{{ route('account.sports-sections.sessions.store', $section) }}" class="sports-section-editor__inline sports-section-session-form" data-venue-court-group>@csrf
        <label class="form-field"><span>Начало</span><input class="form-control" type="datetime-local" name="starts_at" required></label><label class="form-field"><span>Окончание</span><input class="form-control" type="datetime-local" name="ends_at" required></label>
        <label class="form-field"><span>Площадка</span><select class="form-select" name="venue_id" data-venue-select><option value="">Как у секции</option>@foreach($venues as $venue)<option value="{{ $venue->id }}">{{ $venue->name }}</option>@endforeach</select></label>
        <label class="form-field"><span>Зал</span><select class="form-select" name="venue_court_id" data-court-select><option value="">Как у секции</option>@foreach($venues as $venue)@foreach($venue->courts as $court)<option value="{{ $court->id }}" data-venue-id="{{ $venue->id }}">{{ $court->name }}</option>@endforeach @endforeach</select></label>
        <label class="form-field"><span>Цена только для этого занятия</span><input class="form-control" name="price_override" type="number" min="0" step="0.01" placeholder="Не менять"></label><button class="btn btn--primary btn--sm" type="submit">Создать занятие</button>
    </form>
    <div class="sports-section-editor__sessions">
    @foreach($section->trainingSessions as $session)
        @php($sessionPrice = $session->confirmed_price_minor ?? $session->price_override_minor ?? $section->single_session_price_minor)
        <article class="sports-section-session-card">
            <header class="sports-section-session-card__header"><div><strong>{{ $session->starts_at->format('d.m.Y H:i') }}–{{ $session->ends_at->format('H:i') }}</strong><span class="text-muted">{{ $session->venue?->name ?? 'Место не указано' }}@if($session->venueCourt) · {{ $session->venueCourt->name }}@endif</span></div><span class="sports-section-session-card__status">{{ $session->status->label() }}</span></header>
            <div class="sports-section-session-card__meta"><span>{{ $session->participants->count() }} участников</span><span>{{ $session->coaches->count() }} тренеров</span><span>{{ $sessionPrice !== null && $sessionPrice > 0 ? number_format($sessionPrice / 100, 0, ',', ' ').' руб.' : 'Бесплатно' }}</span></div>

            @if($session->status->value === 'planned')
            <details class="sports-section-session-card__details"><summary>Изменить дату, место или цену</summary>
                <form method="POST" action="{{ route('account.sports-sections.sessions.update', [$section, $session]) }}" class="sports-section-editor__inline" data-venue-court-group>@csrf @method('PUT')
                    <label class="form-field"><span>Начало</span><input class="form-control" type="datetime-local" name="starts_at" value="{{ $session->starts_at->format('Y-m-d\TH:i') }}" required></label>
                    <label class="form-field"><span>Окончание</span><input class="form-control" type="datetime-local" name="ends_at" value="{{ $session->ends_at->format('Y-m-d\TH:i') }}" required></label>
                    <label class="form-field"><span>Площадка</span><select class="form-select" name="venue_id" data-venue-select><option value="">Не указана</option>@foreach($venues as $venue)<option value="{{ $venue->id }}" @selected($session->venue_id === $venue->id)>{{ $venue->name }}</option>@endforeach</select></label>
                    <label class="form-field"><span>Зал</span><select class="form-select" name="venue_court_id" data-court-select><option value="">Не указан</option>@foreach($venues as $venue)@foreach($venue->courts as $court)<option value="{{ $court->id }}" data-venue-id="{{ $venue->id }}" @selected($session->venue_court_id === $court->id)>{{ $court->name }}</option>@endforeach @endforeach</select></label>
                    <label class="form-field"><span>Цена занятия, руб.</span><input class="form-control" name="price_override" type="number" min="0" step="0.01" value="{{ $session->price_override_minor !== null ? $session->price_override_minor / 100 : '' }}" placeholder="Цена секции"></label>
                    <button class="btn btn--secondary btn--sm" type="submit">Сохранить занятие</button>
                </form>
            </details>
            @endif

            <details class="sports-section-session-card__details"><summary>Состав занятия</summary>
                <p class="text-muted">Изменения здесь относятся только к этому занятию и не меняют постоянный состав секции.</p>
                <div class="sports-section-editor__snapshot"><strong>Участники</strong>@foreach($session->participants as $participant)@php($participantName = trim(($participant->user?->profile?->first_name ?? '').' '.($participant->user?->profile?->last_name ?? '')) ?: $participant->user?->username)<form method="POST" action="{{ route('account.sports-sections.sessions.participants.destroy', [$section, $session, $participant->id]) }}">@csrf @method('DELETE')<span>{{ $participantName }}</span><button class="btn btn--secondary btn--sm">Убрать</button></form>@endforeach
                    <form method="POST" action="{{ route('account.sports-sections.sessions.participants.store', [$section, $session]) }}">@csrf<select class="form-select" name="user_id">@foreach($section->traineeMemberships->filter(fn ($item) => $item->status->value === 'active') as $trainee)@php($candidateName = trim(($trainee->user?->profile?->first_name ?? '').' '.($trainee->user?->profile?->last_name ?? '')) ?: $trainee->user?->username)<option value="{{ $trainee->user_id }}">{{ $candidateName }}</option>@endforeach</select><button class="btn btn--secondary btn--sm">Добавить участника</button></form>
                    <strong>Тренеры</strong>@foreach($session->coaches as $coach)@php($coachName = trim(($coach->user?->profile?->first_name ?? '').' '.($coach->user?->profile?->last_name ?? '')) ?: $coach->user?->username)<form method="POST" action="{{ route('account.sports-sections.sessions.coaches.destroy', [$section, $session, $coach->id]) }}">@csrf @method('DELETE')<span>{{ $coachName }}</span><button class="btn btn--secondary btn--sm">Убрать</button></form>@endforeach
                    <form method="POST" action="{{ route('account.sports-sections.sessions.coaches.store', [$section, $session]) }}">@csrf<select class="form-select" name="membership_id">@foreach($section->coachMemberships as $coachMembership)@php($candidateCoachName = trim(($coachMembership->user?->profile?->first_name ?? '').' '.($coachMembership->user?->profile?->last_name ?? '')) ?: $coachMembership->user?->username)<option value="{{ $coachMembership->id }}">{{ $candidateCoachName }}</option>@endforeach</select><button class="btn btn--secondary btn--sm">Добавить тренера</button></form>
                </div>
            </details>

            <div class="sports-section-session-card__actions">
                @unless($session->event_id)
                    @if($session->venue_id && $session->venue_court_id && in_array($session->status->value, ['planned','confirmed']))
                        <form method="POST" action="{{ route('account.sports-sections.sessions.event', [$section, $session]) }}">@csrf<button class="btn btn--secondary btn--sm" type="submit"><i class="ti ti-world-upload"></i> Опубликовать на MSKBA</button></form>
                    @elseif(in_array($session->status->value, ['planned','confirmed']))
                        <span class="text-muted">Для публикации укажите площадку и зал.</span>
                    @endif
                @else
                    <a class="btn btn--secondary btn--sm" href="{{ route('events.show', $session->event) }}"><i class="ti ti-external-link"></i> Открыть публичное мероприятие</a>
                @endunless
                @if($session->status->value === 'planned')<form method="POST" action="{{ route('account.sports-sections.sessions.transition', [$section, $session]) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="confirmed"><button class="btn btn--primary btn--sm">Подтвердить занятие</button></form>@endif
                @if($session->status->value === 'confirmed')<form method="POST" action="{{ route('account.sports-sections.sessions.transition', [$section, $session]) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="completed"><button class="btn btn--primary btn--sm">Завершить</button></form>@endif
            </div>
            @if(in_array($session->status->value, ['planned','confirmed']))<details class="sports-section-session-card__cancel"><summary>Отменить занятие</summary><form method="POST" action="{{ route('account.sports-sections.sessions.transition', [$section, $session]) }}" class="sports-section-editor__inline">@csrf @method('PATCH')<input type="hidden" name="status" value="cancelled"><input class="form-control" name="reason" required placeholder="Причина отмены"><button class="btn btn--secondary btn--sm">Подтвердить отмену</button></form></details>@endif
        </article>
    @endforeach
    </div>
</section>
@endif
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-venue-court-group]').forEach((group) => {
        const venue = group.querySelector('[data-venue-select]');
        const court = group.querySelector('[data-court-select]');
        if (!venue || !court) return;

        const syncCourts = () => {
            const venueId = venue.value;
            Array.from(court.options).forEach((option) => {
                if (!option.value) return;
                const matches = venueId !== '' && option.dataset.venueId === venueId;
                option.hidden = !matches;
                option.disabled = !matches;
            });
            const selected = court.selectedOptions[0];
            if (selected && selected.value && selected.dataset.venueId !== venueId) court.value = '';
        };

        venue.addEventListener('change', syncCourts);
        syncCourts();
    });

    const pricingType = document.querySelector('[data-pricing-type]');
    const priceField = document.querySelector('[data-price-field]');
    if (pricingType && priceField) {
        const priceInput = priceField.querySelector('input');
        const syncPrice = () => {
            const paid = pricingType.value === 'paid';
            priceField.hidden = !paid;
            if (priceInput) priceInput.disabled = !paid;
        };
        pricingType.addEventListener('change', syncPrice);
        syncPrice();
    }
});
</script>
@endsection
