<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;

/**
 * Quick enable/disable for the Filament venue editor.
 *
 * Always publish through the canonical policy workflow: changing an existing
 * version in place would silently rewrite booking policy history.
 */
final readonly class ToggleVenueBookingPolicyHandler
{
    public function __construct(
        private PublishVenueBookingPolicyHandler $publish,
    ) {}

    public function handle(Venue $venue, User $publisher, bool $enabled): VenueBookingPolicy
    {
        $policy = VenueBookingPolicy::query()
            ->where('venue_id', $venue->id)
            ->where('active_marker', true)
            ->first();

        if ($policy === null) {
            throw new VenueBookingPolicyException(
                'Для включения аренды сначала опубликуйте базовые условия площадки.',
            );
        }

        // An unchanged state does not need another policy version.
        // Recheck permissions and feature flags through the canonical publisher
        // whenever a mutation is actually needed.
        if ($policy->is_enabled === $enabled) {
            return $policy;
        }

        return $this->publish->handle($venue, $publisher, array_replace($policy->only([
            'is_enabled',
            'allows_whole',
            'allows_halves',
            'minimum_duration_minutes',
            'maximum_duration_minutes',
            'time_step_minutes',
            'minimum_lead_time_minutes',
            'maximum_advance_days',
            'currency',
            'whole_price_per_step_minor',
            'half_price_per_step_minor',
            'hold_duration_minutes',
            'allows_hold_extension',
            'maximum_hold_extension_minutes',
            'requires_payment',
            'payment_window_minutes',
            'quote_validity_minutes',
            'cancellation_before_minutes',
        ]), ['is_enabled' => $enabled]));
    }
}
