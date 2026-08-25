<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\UseCases\PublishVenueBookingPolicyHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class VenueBookingPolicyController extends Controller
{
    public function edit(Request $request, Venue $venue, VenueCommercialAccess $access): Response
    {
        abort_unless($access->allows($request->user(), $venue, VenuePermissionEnum::MANAGE_BOOKING_POLICY), 403);

        return ThemeResolver::page('venues.booking-policy', [
            'venue' => $venue,
            'policy' => VenueBookingPolicy::query()
                ->where('venue_id', $venue->id)
                ->where('active_marker', true)
                ->first(),
        ]);
    }

    public function update(
        Request $request,
        Venue $venue,
        PublishVenueBookingPolicyHandler $publish,
        VenueCommercialAccess $access,
    ): RedirectResponse {
        abort_unless($access->allows($request->user(), $venue, VenuePermissionEnum::MANAGE_BOOKING_POLICY), 403);
        $validated = $request->validate([
            'is_enabled' => ['nullable', 'boolean'],
            'allows_whole' => ['nullable', 'boolean'],
            'allows_halves' => ['nullable', 'boolean'],
            'minimum_duration_minutes' => ['required', 'integer', 'min:15', 'max:1440'],
            'maximum_duration_minutes' => ['required', 'integer', 'min:15', 'max:1440'],
            'time_step_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'minimum_lead_time_minutes' => ['required', 'integer', 'min:0', 'max:525600'],
            'maximum_advance_days' => ['required', 'integer', 'min:1', 'max:730'],
            'currency' => ['required', 'string', 'size:3'],
            'whole_price_per_step_minor' => ['required', 'integer', 'min:0'],
            'half_price_per_step_minor' => ['nullable', 'integer', 'min:0'],
            'hold_duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'requires_payment' => ['nullable', 'boolean'],
            'payment_window_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'quote_validity_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'cancellation_before_minutes' => ['nullable', 'integer', 'min:0', 'max:525600'],
        ]);

        foreach (['is_enabled', 'allows_whole', 'allows_halves', 'requires_payment'] as $boolean) {
            $validated[$boolean] = $request->boolean($boolean);
        }

        try {
            $policy = $publish->handle($venue, $request->user(), $validated);
        } catch (VenueBookingPolicyException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', "Опубликована версия условий №{$policy->version}.");
    }
}
