@php
    $courtCount = count($venue->courts ?? []);
    $selectedCourt = $venue->selectedCourt ?? null;
    $courtCountLabel = ($courtCount % 10 === 1 && $courtCount % 100 !== 11)
        ? 'зал'
        : (in_array($courtCount % 10, [2, 3, 4], true) && ! in_array($courtCount % 100, [12, 13, 14], true) ? 'зала' : 'залов');
    $pickerId = 'venue-court-picker-'.$venue->id;
    $selectedCourtName = $selectedCourt['name'] ?? '';
    $selectedCourtShortName = mb_strlen($selectedCourtName) > 10
        ? mb_substr($selectedCourtName, 0, 10).'...'
        : $selectedCourtName;
    $courtStatusLabel = $venue->isOpen ? 'Открыта' : 'Закрыта';
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
            <details class="venue-court-dropdown" data-venue-court-dropdown>
                <summary
                    class="venue-pill venue-court-picker-trigger"
                    aria-label="Выбрать зал. Сейчас: {{ $selectedCourtName }}"
                    title="Сейчас: {{ $selectedCourtName }}"
                    data-tooltip-variant="title"
                >
                    <span
                        @class([
                            'venue-court-picker-trigger__status',
                            'is-open' => $venue->isOpen,
                            'is-closed' => ! $venue->isOpen,
                        ])
                        role="img"
                        aria-label="Площадка {{ mb_strtolower($courtStatusLabel) }}"
                        title="{{ $courtStatusLabel }}"
                    ></span>
                    <span class="venue-court-picker-trigger__label">{{ $selectedCourtShortName }}</span>
                    <i class="ti ti-chevron-down venue-court-picker-trigger__chevron" aria-hidden="true"></i>
                </summary>

                <div class="venue-court-dropdown__menu" role="menu" aria-label="Выбор зала">
                    @foreach($venue->courts as $court)
                        @php
                            $courtUrl = $court['isPrimary']
                                ? route('venues.show', $venue->routeIdentifier())
                                : route('venues.courts.show', [$venue->routeIdentifier(), $court['routeIdentifier']]);
                            $isSelected = (int) $court['id'] === (int) $selectedCourt['id'];
                        @endphp
                        <a
                            href="{{ $courtUrl }}"
                            @class(['venue-court-dropdown__option', 'is-selected' => $isSelected])
                            role="menuitem"
                            @if($isSelected) aria-current="page" @endif
                        >
                            <span
                                @class([
                                    'venue-court-dropdown__status',
                                    'is-open' => $venue->isOpen,
                                    'is-closed' => ! $venue->isOpen,
                                ])
                                aria-hidden="true"
                            ></span>
                            <span class="venue-court-dropdown__copy">
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
            </details>
        @else
            <span
                class="venue-pill venue-court-picker-trigger venue-court-picker-trigger--single"
                title="{{ $selectedCourtName }}"
                data-tooltip-variant="title"
            >
                <span
                    @class([
                        'venue-court-picker-trigger__status',
                        'is-open' => $venue->isOpen,
                        'is-closed' => ! $venue->isOpen,
                    ])
                    role="img"
                    aria-label="Площадка {{ mb_strtolower($courtStatusLabel) }}"
                    title="{{ $courtStatusLabel }}"
                ></span>
                <span class="venue-court-picker-trigger__label">{{ $selectedCourtShortName }}</span>
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
