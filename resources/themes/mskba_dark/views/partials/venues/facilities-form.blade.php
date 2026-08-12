@php
    $venueRevision = $venueRevision ?? null;
    $revisionPayload = $venueRevision?->payload ?? [];
    $revisionDetails = is_array($revisionPayload['details'] ?? null) ? $revisionPayload['details'] : [];
    $revisionTags = is_array($revisionPayload['tags'] ?? null)
        ? $revisionPayload['tags']
        : $venue->tags->pluck('name')->all();
    $action = $action ?? route('account.venues.update', $venue->routeIdentifier());
    $submitLabel = $submitLabel ?? 'Сохранить характеристики';
@endphp

<form method="POST" action="{{ $action }}" class="venue-facilities-form">
    @csrf
    @method('PUT')

    <input type="hidden" name="facilities_present" value="1">
    <input type="hidden" name="name" value="{{ $revisionDetails['name'] ?? $venue->name }}">
    <input type="hidden" name="type" value="{{ $revisionDetails['type'] ?? $venue->type->value }}">
    <input type="hidden" name="short_description" value="{{ $revisionDetails['short_description'] ?? $venue->short_description }}">
    <input type="hidden" name="full_description" value="{{ $revisionDetails['full_description'] ?? $venue->full_description }}">
    <input type="hidden" name="tags" value="{{ implode(', ', $revisionTags) }}">

    <fieldset class="venue-form__fieldset" @disabled($readOnly ?? false)>
        @include('theme::partials.venues.facilities-editor', [
            'venue' => $venue,
            'venueRevision' => $venueRevision,
            'revisionPayload' => $revisionPayload,
            'revisionDetails' => $revisionDetails,
        ])
    </fieldset>

    <div class="d-flex flex-wrap align-items-center gap-3">
        <button type="submit" class="btn btn--primary btn--sm" @disabled($readOnly ?? false)>
            {{ $submitLabel }}
        </button>
        @if($readOnly ?? false)
            <span class="venue-form__read-only-message">Дождитесь результата модерации.</span>
        @endif
    </div>
</form>
