<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Application\Services\VenueTariffGrid;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Modules\VenueIntegration\Domain\Models\VenueCourtExternalScheduleImport;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VenueTariffGridController extends Controller
{
    public function edit(
        Request $request, Venue $venue, VenueCommercialAccess $access, VenueTariffGrid $grid
    ): Response {
        $input = $request->validate([
            'court' => ['nullable', 'integer', 'min:1'],
            'mode' => ['nullable', Rule::in(['weekly', 'date'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        [$policy, $court] = $this->context($request, $venue, $access, $input['court'] ?? null);
        $import = $court === null ? null : VenueCourtExternalScheduleImport::query()
            ->where('venue_court_id', $court->id)->first();
        $mode = $input['mode'] ?? ($import === null ? 'weekly' : 'date');
        $date = $input['date'] ?? ($import?->first_date?->format('Y-m-d')
            ?? CarbonImmutable::now($venue->schedule?->timezone ?: 'Europe/Moscow')->toDateString());

        return ThemeResolver::page('venues.tariff-grid', [
            'venue' => $venue,
            'courts' => $venue->courts()->get(),
            'court' => $court,
            'policy' => $policy,
            'import' => $import,
            'mode' => $mode,
            'date' => $date,
            'step' => $grid->step($policy, $court),
            'rows' => $grid->read($venue, $policy, $court, $mode, $date),
        ]);
    }

    public function store(
        Request $request, Venue $venue, VenueCommercialAccess $access, VenueTariffGrid $grid
    ): RedirectResponse {
        $input = $request->validate([
            'court_id' => ['nullable', 'integer', 'min:1'],
            'mode' => ['required', Rule::in(['weekly', 'date'])],
            'date' => ['required_if:mode,date', 'nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'array', 'max:7'],
            'days.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'starts_at' => ['required_without:ranges', 'date_format:H:i'],
            'ends_at' => ['required_without:ranges', 'date_format:H:i'],
            'operation' => ['required_without:ranges', Rule::in(['set', 'clear'])],
            'whole_price_per_step' => ['nullable', 'string', 'max:32', 'regex:/^\d+(?:[.,]\d{1,2})?$/'],
            'half_price_per_step' => ['nullable', 'string', 'max:32', 'regex:/^\d+(?:[.,]\d{1,2})?$/'],
            'exceptions' => ['nullable', 'array', 'max:100'],
            'exceptions.*.starts_at' => ['nullable', 'date_format:H:i'],
            'exceptions.*.day_of_week' => ['nullable', 'integer', 'between:1,7'],
            'exceptions.*.operation' => ['nullable', Rule::in(['set', 'clear'])],
            'exceptions.*.whole_price_per_step' => ['nullable', 'string', 'max:32', 'regex:/^\d+(?:[.,]\d{1,2})?$/'],
            'exceptions.*.half_price_per_step' => ['nullable', 'string', 'max:32', 'regex:/^\d+(?:[.,]\d{1,2})?$/'],

            'ranges' => ['nullable', 'array', 'max:16'],
            'ranges.*.days' => ['nullable', 'array', 'max:7'],
            'ranges.*.days.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'ranges.*.starts_at' => ['required', 'date_format:H:i'],
            'ranges.*.ends_at' => ['required', 'date_format:H:i'],
            'ranges.*.operation' => ['required', Rule::in(['set', 'clear'])],
            'ranges.*.whole_price_per_step' => ['nullable', 'string', 'max:32', 'regex:/^\d+(?:[.,]\d{1,2})?$/'],
            'ranges.*.half_price_per_step' => ['nullable', 'string', 'max:32', 'regex:/^\d+(?:[.,]\d{1,2})?$/'],
            'ranges.*.exceptions' => ['nullable', 'array', 'max:100'],
            'ranges.*.exceptions.*.starts_at' => ['required', 'date_format:H:i'],
            'ranges.*.exceptions.*.day_of_week' => ['nullable', 'integer', 'between:1,7'],
            'ranges.*.exceptions.*.operation' => ['required', Rule::in(['set', 'clear'])],
            'ranges.*.exceptions.*.whole_price_per_step' => ['nullable', 'string', 'max:32', 'regex:/^\d+(?:[.,]\d{1,2})?$/'],
            'ranges.*.exceptions.*.half_price_per_step' => ['nullable', 'string', 'max:32', 'regex:/^\d+(?:[.,]\d{1,2})?$/'],
        ]);

        [$policy, $court] = $this->context($request, $venue, $access, $input['court_id'] ?? null);
        $count = isset($input['ranges'])
            ? $grid->applyMany(
                $venue, $policy, $court,
                $input['mode'], $input['date'] ?? now('Europe/Moscow')->toDateString(),
                array_values($input['ranges']), $request->user()?->id,
            )
            : $grid->apply($venue, $policy, $court, $input, $request->user()?->id);

        return redirect()->route('account.venues.tariff-grid.edit', array_filter([
            'venue' => $venue->id,
            'court' => $court?->id,
            'mode' => $input['mode'],
            'date' => $input['mode'] === 'date' ? $input['date'] : null,
        ], fn ($value) => $value !== null))
            ->with('status', 'Тарифы обновлены: '.$count.' временных шагов.');
    }

    /** @return array{0: VenueBookingPolicy, 1: ?VenueCourt} */
    private function context(Request $request, Venue $venue, VenueCommercialAccess $access, mixed $courtId): array
    {
        $user = $request->user();
        abort_unless($user !== null, 403);
        $court = $courtId === null ? null : $venue->courts()->whereKey((int) $courtId)->firstOrFail();
        abort_unless($court === null
            ? $access->allows($user, $venue, VenuePermissionEnum::MANAGE_BOOKING_POLICY)
            : $access->allowsForCourt($user, $court, VenuePermissionEnum::MANAGE_BOOKING_POLICY), 403);

        $policy = VenueBookingPolicy::query()
            ->where('venue_id', $venue->id)->where('active_marker', true)->first();
        if ($policy === null) {
            throw ValidationException::withMessages(['venue' => 'Сначала опубликуйте базовые условия аренды площадки.']);
        }

        return [$policy, $court];
    }
}
