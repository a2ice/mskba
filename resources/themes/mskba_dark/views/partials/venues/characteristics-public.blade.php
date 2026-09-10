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
@endphp

@if($characteristics !== null || $selectedCourt !== null)
    <section class="venue-show-section venue-characteristics-public" data-venue-characteristics-public>
        <div class="venue-show-section__heading">
            <h2>Характеристики площадки</h2>
        </div>

        <div class="venue-characteristics-public__grid">
            @if($selectedCourt !== null)
                <article class="venue-characteristics-public__court-card">
                    <i class="ti ti-building-arena" aria-hidden="true"></i>
                    <span>Количество залов</span>
                    <div class="venue-characteristics-public__court-value">
                        <strong>{{ $courtCount }}</strong>
                        @if($courtCount > 1)
                            <label class="visually-hidden" for="venue-characteristics-court-selector-{{ $venue->id }}">Выберите зал</label>
                            <select
                                id="venue-characteristics-court-selector-{{ $venue->id }}"
                                class="venue-characteristics-public__court-select"
                                data-venue-court-selector
                                aria-label="Выберите зал. Сейчас: {{ $selectedCourt['name'] }}"
                            >
                                @foreach($courts as $court)
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
        .venue-characteristics-public__court-select{min-width:0;max-width:220px;height:31px;padding:4px 28px 4px 9px;border:1px solid var(--line-strong);border-radius:9px;background:var(--field);color:var(--text);font:inherit;font-size:12px;font-weight:700}
        @media(max-width:780px){.venue-characteristics-public__grid{grid-template-columns:1fr}.venue-characteristics-public__court-select{max-width:min(70vw,260px)}}
    </style>

    <script>
        (() => {
            const block = document.querySelector('[data-venue-characteristics-public]');
            const amenities = document.getElementById('amenities');
            if (block && amenities) amenities.before(block);
        })();
    </script>
@endif
