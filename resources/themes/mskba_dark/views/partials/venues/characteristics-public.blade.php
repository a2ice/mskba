@php
    $characteristics = isset($venue?->id)
        ? \App\Modules\Venue\Domain\Models\VenueCharacteristic::query()->where('venue_id', $venue->id)->first()
        : null;
    $conditionLabel = static fn (?int $value): ?string => match ($value) {
        1 => 'Очень плохое',
        2 => 'Плохое',
        3 => 'Удовлетворительное',
        4 => 'Хорошее',
        5 => 'Отличное',
        default => null,
    };
    $marking = $characteristics?->marking_condition ?? $characteristics?->first_hoop_marking;
    $courts = $venue->courts ?? [];
    $selectedCourt = $venue->selectedCourt ?? null;
    $courtCount = count($courts);
    $hoopsCount = $selectedCourt['hoopsCount'] ?? $characteristics?->hoops_count;
    $surfaceLabel = $selectedCourt['surfaceLabel'] ?? null;
    $pickerId = 'venue-court-picker-'.$venue->id;
@endphp

@if($characteristics !== null || $selectedCourt !== null)
    <section class="venue-show-section venue-characteristics-public" data-venue-characteristics-public>
        <div class="venue-show-section__heading">
            <h2>Характеристики площадки</h2>
        </div>

        <div class="venue-characteristics-public__grid">
            @if($selectedCourt !== null)
                <article class="venue-characteristics-public__court-card">
                    <i class="ti ti-layout-grid" aria-hidden="true"></i>
                    <span>Количество залов</span>
                    <div class="venue-characteristics-public__court-value">
                        <strong>{{ $courtCount }}</strong>
                        @if($courtCount > 1)
                            <button
                                type="button"
                                class="venue-characteristics-public__court-trigger"
                                aria-label="Выбрать зал. Сейчас: {{ $selectedCourt['name'] }}"
                                title="Сейчас: {{ $selectedCourt['name'] }}"
                                data-tooltip-variant="title"
                                data-venue-court-picker-trigger
                                data-handler="modal"
                                data-modal-action="open"
                                data-modal-target="{{ $pickerId }}"
                            >
                                Выбрать зал
                                <i class="ti ti-chevron-down" aria-hidden="true"></i>
                            </button>
                        @endif
                    </div>
                </article>
            @endif

            @if($hoopsCount)
                <article>
                    <i class="ti ti-ball-basketball" aria-hidden="true"></i>
                    <span>Количество колец</span>
                    <strong>{{ $hoopsCount }}</strong>
                </article>
            @endif

            @if($surfaceLabel)
                <article>
                    <i class="ti ti-texture" aria-hidden="true"></i>
                    <span>Покрытие</span>
                    <strong>{{ $surfaceLabel }}</strong>
                </article>
            @endif

            @if($conditionLabel($characteristics?->hoops_condition))
                <article>
                    <i class="ti ti-target-arrow" aria-hidden="true"></i>
                    <span>Состояние колец</span>
                    <strong>{{ $characteristics->hoops_condition }}/5 — {{ $conditionLabel($characteristics->hoops_condition) }}</strong>
                </article>
            @endif

            @if($conditionLabel($characteristics?->surface_condition))
                <article>
                    <i class="ti ti-texture" aria-hidden="true"></i>
                    <span>Состояние покрытия</span>
                    <strong>{{ $characteristics->surface_condition }}/5 — {{ $conditionLabel($characteristics->surface_condition) }}</strong>
                </article>
            @endif

            @if($marking)
                <article>
                    <i class="ti ti-line" aria-hidden="true"></i>
                    <span>Разметка</span>
                    <strong>{{ $marking->label() }}</strong>
                </article>
            @endif
        </div>
    </section>

    <style>
        .venue-characteristics-public__grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
        .venue-characteristics-public__grid article{display:grid;grid-template-columns:34px 1fr;gap:3px 11px;align-items:center;min-height:76px;padding:14px;border:1px solid var(--line);border-radius:13px;background:var(--surface-raised)}
        .venue-characteristics-public__grid i{grid-row:1/3;color:var(--accent-text);font-size:25px}
        .venue-characteristics-public__grid span{color:var(--muted);font-size:12px}
        .venue-characteristics-public__grid strong{font-size:14px}
        .venue-characteristics-public__court-card{grid-template-rows:auto auto}
        .venue-characteristics-public__court-value{display:flex;align-items:center;gap:9px;min-width:0}
        .venue-characteristics-public__court-trigger{display:inline-flex;align-items:center;gap:5px;border:0;padding:0;color:var(--accent-text);background:transparent;font:inherit;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap}
        .venue-characteristics-public__court-trigger i{grid-row:auto;color:currentColor;font-size:14px}
        .venue-characteristics-public__court-trigger:hover,.venue-characteristics-public__court-trigger:focus-visible{color:#fff;outline:none}
        .venue-characteristics-public__court-trigger.ui-tooltip-source--title{text-decoration:none}
        @media(max-width:780px){.venue-characteristics-public__grid{grid-template-columns:1fr}}
    </style>

    <script>
        (() => {
            const block = document.querySelector('[data-venue-characteristics-public]');
            const amenities = document.getElementById('amenities');
            if (block && amenities) amenities.before(block);
        })();
    </script>
@endif
