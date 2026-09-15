@php
    $venueSidebarActive = 'conditions';
    $pending = $venue->hasPendingModerationRequest();
    $details = $venueRevision?->payload['details'] ?? [];
    $accessType = $details['access_type'] ?? ($venue->requires_payment === null ? 'unknown' : ($venue->requires_payment ? 'paid' : 'free'));
    $bookingApproval = $details['requires_booking_approval'] ?? $venue->requires_booking_approval;
    $breadcrumbs = [
        ['label' => 'Аккаунт', 'url' => route('account')],
        ['label' => 'Мои площадки', 'url' => route('account.venues')],
        ['label' => $venue->name, 'url' => route('account.venues.show', $venue->routeIdentifier())],
        ['label' => 'Условия'],
    ];
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => 'Условия · '.$venue->name,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => 'Условия',
    'contentSubtitle' => $venue->name,
    'sidebarLabel' => 'Управление площадкой',
])

@section('section-sidebar')
    @include('theme::partials.venues.internal-sidebar')
@endsection

@section('section-content')
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($pending)
        <div class="alert alert-warning">Площадка находится на модерации. Условия можно изменить после решения модератора.</div>
    @elseif($venue->status === \App\Modules\Venue\Domain\Enums\VenueStatusEnum::CONFIRMED)
        <p class="form-hint">Изменения условий появятся на странице площадки после модерации.</p>
    @endif
    <form method="POST" action="{{ route('account.venues.conditions.update', $venue->routeIdentifier()) }}">
        @csrf
        @method('PUT')
        <fieldset @disabled($pending)>
            <div class="form-group field mb-4">
                <label for="venueAccessType" class="form-label">Условия оплаты</label>
                <select id="venueAccessType" name="access_type" class="form-select @error('access_type') is-invalid @enderror" required>
                    <option value="unknown" @selected(old('access_type', $accessType) === 'unknown')>Не указано</option>
                    <option value="free" @selected(old('access_type', $accessType) === 'free')>Бесплатно</option>
                    <option value="paid" @selected(old('access_type', $accessType) === 'paid')>Платно</option>
                </select>
                <p class="form-hint">«Не указано» не считается бесплатным доступом и не подтверждает бронь автоматически.</p>
                @error('access_type')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            @include('theme::partials.forms.toggle', [
                'name' => 'requires_booking_approval',
                'id' => 'venueRequiresBookingApproval',
                'checked' => (bool) old('requires_booking_approval', $bookingApproval),
                'title' => 'Требуется подтверждение бронирования',
                'description' => 'Заявка на бронь должна быть подтверждена ответственным за площадку.',
            ])
            <button type="submit" class="btn btn--primary btn--sm">Сохранить условия</button>
        </fieldset>
    </form>
    @if($venueRevision !== null)
        <a href="{{ route('account.venues.status', $venue->routeIdentifier()) }}" class="btn btn--secondary btn--sm mt-3">Открыть модерацию</a>
    @endif
@endsection
