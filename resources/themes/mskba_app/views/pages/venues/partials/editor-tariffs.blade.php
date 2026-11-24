@php
    $tariffPolicy = \App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy::query()
        ->where('venue_id', $venue->id)->where('active_marker', true)->first();
    $tariffAccess = app(\App\Modules\Venue\Application\Services\VenueCommercialAccess::class);
    $tariffPermission = \App\Modules\Venue\Domain\Enums\VenuePermissionEnum::MANAGE_BOOKING_POLICY;
    $tariffUser = auth()->user();
    $canEditVenueTariff = $tariffUser !== null && $tariffAccess->allows($tariffUser, $venue, $tariffPermission);
    $tariffGrid = app(\App\Modules\VenueBooking\Application\Services\VenueTariffGrid::class);
    $chosenCourt = request()->integer('court') ?: ((int) (old('return_to_schedule') ? old('court_id', 0) : 0));
    $chosenMode = in_array(request()->query('mode'), ['weekly', 'date'], true)
        ? request()->query('mode') : 'weekly';
    $candidateDate = request()->query('date');
    $chosenDate = is_string($candidateDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidateDate)
        && \Carbon\CarbonImmutable::hasFormat($candidateDate, 'Y-m-d')
            ? $candidateDate
            : now($venue->schedule?->timezone ?: 'Europe/Moscow')->toDateString();
@endphp

<section class="panel app-venue-editor__panel" id="venue-tariff-panel-venue" data-venue-tariffs>
    <h2>Тарифная сетка площадки</h2>
    <p class="app-venue-editor__muted">
        Сначала задайте общие цены по дням недели: все залы без собственных тарифов наследуют эту сетку.
        Пустая цена означает наследование базовой цены площадки, а «Очистить ручную цену» возвращает её.
    </p>
    @if(! $tariffPolicy)
        <p class="app-venue-editor__muted">Для настройки цен по времени сначала опубликуйте базовые условия аренды площадки.</p>
    @elseif(! $canEditVenueTariff)
        <p class="app-venue-editor__muted">Недостаточно прав для редактирования тарифов площадки.</p>
    @else
        @include('venues.partials.tariff-grid', [
            'embedded' => true,
            'venue' => $venue,
            'courts' => $venue->courts,
            'court' => null,
            'policy' => $tariffPolicy,
            'import' => null,
            'mode' => $chosenCourt === 0 ? $chosenMode : 'weekly',
            'date' => $chosenDate,
            'step' => $tariffGrid->step($tariffPolicy, null),
            'rows' => $tariffGrid->read($venue, $tariffPolicy, null, $chosenCourt === 0 ? $chosenMode : 'weekly', $chosenDate),
        ])
    @endif
</section>

<section class="panel app-venue-editor__panel" data-venue-court-tariffs>
    <h2>Тарифы залов и кортов</h2>
    <p class="app-venue-editor__muted">
        Каждый зал по умолчанию использует цены площадки. Раскройте нужный зал, чтобы настроить
        собственные диапазоны или точечные исключения. Другие залы от этого не изменятся.
    </p>

    @forelse($venue->courts as $pricingCourt)
        @php
            $canEditCourtTariff = $tariffUser !== null
                && $tariffAccess->allowsForCourt($tariffUser, $pricingCourt, $tariffPermission);
            $courtMode = $chosenCourt === (int) $pricingCourt->id ? $chosenMode : 'weekly';
            $courtImport = $tariffPolicy === null ? null
                : \App\Modules\VenueIntegration\Domain\Models\VenueCourtExternalScheduleImport::query()
                    ->where('venue_court_id', $pricingCourt->id)->first();
        @endphp
        <details class="app-venue-editor__tariff-court" id="venue-tariff-panel-court-{{ $pricingCourt->id }}"
            @if($chosenCourt === (int) $pricingCourt->id) open @endif>
            <summary>
                <strong>{{ $pricingCourt->publicName() }}</strong>
                <span class="app-venue-editor__muted">Наследование от площадки · раскрыть тарифы</span>
            </summary>
            <div class="app-venue-editor__tariff-court-body">
                @if(! $tariffPolicy)
                    <p class="app-venue-editor__muted">Сначала настройте базовый тариф площадки.</p>
                @elseif(! $canEditCourtTariff)
                    <p class="app-venue-editor__muted">У вас нет прав на управление тарифами этого зала.</p>
                @else
                    @include('venues.partials.tariff-grid', [
                        'embedded' => true,
                        'venue' => $venue,
                        'courts' => $venue->courts,
                        'court' => $pricingCourt,
                        'policy' => $tariffPolicy,
                        'import' => $courtImport,
                        'mode' => $courtMode,
                        'date' => $chosenDate,
                        'step' => $tariffGrid->step($tariffPolicy, $pricingCourt),
                        'rows' => $tariffGrid->read($venue, $tariffPolicy, $pricingCourt, $courtMode, $chosenDate),
                    ])
                @endif
            </div>
        </details>
    @empty
        <p class="app-venue-editor__muted">Залы пока не созданы. Добавьте их во вкладке «Залы и корты».</p>
    @endforelse
</section>

<style>
    .app-venue-editor__tariff-court {
        border: 1px solid var(--border-color, #d6dce5);
        border-radius: 12px;
        margin-top: 12px;
        overflow: hidden;
    }
    .app-venue-editor__tariff-court > summary {
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 16px;
        list-style-position: inside;
    }
    .app-venue-editor__tariff-court[open] > summary {
        border-bottom: 1px solid var(--border-color, #d6dce5);
    }
    .app-venue-editor__tariff-court-body { padding: 12px 16px 16px; }
    @media(max-width:680px) {
        .app-venue-editor__tariff-court > summary { flex-direction: column; align-items: flex-start; }
        .app-venue-editor__tariff-court-body { padding: 8px; }
    }
</style>
