@php
    $courtCount = count($venue->courts ?? []);
    $selectedCourt = $venue->selectedCourt ?? null;
@endphp

@if($selectedCourt)
    <div
        class="venue-court-context"
        data-venue-court-context
        data-venue-id="{{ $venue->id }}"
        data-venue-route-identifier="{{ $venue->routeIdentifier() }}"
        data-venue-court-id="{{ $selectedCourt['id'] }}"
        data-venue-court-identifier="{{ $selectedCourt['routeIdentifier'] }}"
    >
        <span class="venue-court-context__separator" aria-hidden="true">·</span>

        @if($courtCount > 1)
            <label class="visually-hidden" for="venue-heading-court-selector-{{ $venue->id }}">Выберите зал</label>
            <select
                id="venue-heading-court-selector-{{ $venue->id }}"
                class="venue-court-selector"
                data-venue-court-selector
                aria-label="Выберите зал. Сейчас: {{ $selectedCourt['name'] }}"
            >
                @foreach($venue->courts as $court)
                    @php
                        $courtUrl = $court['isPrimary']
                            ? route('venues.show', $venue->routeIdentifier())
                            : route('venues.courts.show', [$venue->routeIdentifier(), $court['routeIdentifier']]);
                    @endphp
                    <option value="{{ $courtUrl }}" @selected((int) $court['id'] === (int) $selectedCourt['id'])>
                        {{ $court['name'] }}{{ $court['isPrimary'] ? ' · основной' : '' }}
                    </option>
                @endforeach
            </select>
        @else
            <span class="venue-court-selector venue-court-selector--single">1 зал</span>
        @endif
    </div>
@endif
