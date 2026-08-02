@php
    $characteristics = isset($venue) && isset($venue->id)
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
@endphp

@if($characteristics !== null)
    <section class="venue-show-section venue-characteristics-public" data-venue-characteristics-public>
        <div class="venue-show-section__heading">
            <h2>Характеристики площадки</h2>
        </div>

        <div class="venue-characteristics-public__grid">
            @if($characteristics->hoops_count)
                <article>
                    <i class="ti ti-basketball-hoop" aria-hidden="true"></i>
                    <span>Количество колец</span>
                    <strong>{{ $characteristics->hoops_count }}</strong>
                </article>
            @endif

            @if($conditionLabel($characteristics->hoops_condition))
                <article>
                    <i class="ti ti-target-arrow" aria-hidden="true"></i>
                    <span>Состояние колец</span>
                    <strong>{{ $characteristics->hoops_condition }}/5 — {{ $conditionLabel($characteristics->hoops_condition) }}</strong>
                </article>
            @endif

            @if($conditionLabel($characteristics->surface_condition))
                <article>
                    <i class="ti ti-texture" aria-hidden="true"></i>
                    <span>Состояние покрытия</span>
                    <strong>{{ $characteristics->surface_condition }}/5 — {{ $conditionLabel($characteristics->surface_condition) }}</strong>
                </article>
            @endif

            @if($characteristics->first_hoop_marking)
                <article>
                    <i class="ti ti-line" aria-hidden="true"></i>
                    <span>Разметка у первого кольца</span>
                    <strong>{{ $characteristics->first_hoop_marking->label() }}</strong>
                </article>
            @endif

            @if($characteristics->hoops_count === 2 && $characteristics->second_hoop_marking)
                <article>
                    <i class="ti ti-line" aria-hidden="true"></i>
                    <span>Разметка у второго кольца</span>
                    <strong>{{ $characteristics->second_hoop_marking->label() }}</strong>
                </article>
            @endif
        </div>
    </section>

    <style>
        .venue-characteristics-public__grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }
        .venue-characteristics-public__grid article {
            display: grid;
            grid-template-columns: 34px 1fr;
            gap: 3px 11px;
            align-items: center;
            min-height: 76px;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 13px;
            background: var(--surface-raised);
        }
        .venue-characteristics-public__grid i {
            grid-row: 1 / 3;
            color: var(--accent-text);
            font-size: 25px;
        }
        .venue-characteristics-public__grid span {
            color: var(--muted);
            font-size: 12px;
        }
        .venue-characteristics-public__grid strong {
            font-size: 14px;
        }
        @media (max-width: 780px) {
            .venue-characteristics-public__grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <script>
        (() => {
            const block = document.querySelector('[data-venue-characteristics-public]');
            const amenities = document.getElementById('amenities');
            if (block && amenities) amenities.before(block);
        })();
    </script>
@endif
