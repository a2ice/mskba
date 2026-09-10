@php
    $courtCount = count($venue->courts ?? []);
    $selectedCourt = $venue->selectedCourt ?? null;
    $courtCountLabel = ($courtCount % 10 === 1 && $courtCount % 100 !== 11)
        ? 'зал'
        : (in_array($courtCount % 10, [2, 3, 4], true) && ! in_array($courtCount % 100, [12, 13, 14], true) ? 'зала' : 'залов');
    $pickerId = 'venue-court-picker-'.$venue->id;
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
        @if($courtCount > 1)
            <button
                type="button"
                class="venue-pill venue-court-picker-trigger"
                aria-label="Выбрать зал. Сейчас: {{ $selectedCourt['name'] }}"
                title="Сейчас: {{ $selectedCourt['name'] }}"
                data-tooltip-variant="title"
                data-venue-court-picker-trigger
                data-handler="modal"
                data-modal-action="open"
                data-modal-target="{{ $pickerId }}"
            >
                <i class="ti ti-layout-grid" aria-hidden="true"></i>
                <span>{{ $courtCount }} {{ $courtCountLabel }}</span>
                <i class="ti ti-chevron-down venue-court-picker-trigger__chevron" aria-hidden="true"></i>
            </button>
        @else
            <span
                class="venue-pill venue-court-picker-trigger venue-court-picker-trigger--single"
                title="{{ $selectedCourt['name'] }}"
                data-tooltip-variant="title"
            >
                <i class="ti ti-layout-grid" aria-hidden="true"></i>
                <span>1 зал</span>
            </span>
        @endif
    </div>

    @if($courtCount > 1)
        @component('theme::partials.modal.layout', [
            'id' => $pickerId,
            'dialogClass' => 'venue-court-picker__dialog',
        ])
            <div class="venue-court-picker" data-venue-court-picker-modal>
                <p class="venue-court-picker__eyebrow">{{ $venue->name }}</p>
                <h2 class="modal_title" id="modal-title-{{ $pickerId }}">Выбор зала</h2>
                <p class="venue-court-picker__hint">Выберите игровое пространство. Занятость, мероприятия и бронирование будут показаны для выбранного зала.</p>

                <div class="venue-court-picker__list">
                    @foreach($venue->courts as $court)
                        @php
                            $courtUrl = $court['isPrimary']
                                ? route('venues.show', $venue->routeIdentifier())
                                : route('venues.courts.show', [$venue->routeIdentifier(), $court['routeIdentifier']]);
                            $isSelected = (int) $court['id'] === (int) $selectedCourt['id'];
                        @endphp
                        <a
                            href="{{ $courtUrl }}"
                            @class(['venue-court-picker__option', 'is-selected' => $isSelected])
                            @if($isSelected) aria-current="page" @endif
                        >
                            <span class="venue-court-picker__option-icon" aria-hidden="true">
                                <i class="ti {{ $isSelected ? 'ti-check' : 'ti-layout-grid' }}"></i>
                            </span>
                            <span class="venue-court-picker__option-copy">
                                <strong>{{ $court['name'] }}</strong>
                                <small>
                                    {{ $court['hoopsCount'] }} {{ $court['hoopsCount'] === 1 ? 'кольцо' : 'кольца' }}
                                    @if($court['surfaceLabel']) · {{ $court['surfaceLabel'] }} @endif
                                </small>
                            </span>
                            @if($court['isPrimary'])
                                <span class="venue-court-picker__primary">Основной</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>
        @endcomponent
    @endif
@endif
