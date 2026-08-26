<?php

namespace App\Modules\VenueBooking\Application\Queries;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\VenueBooking\Application\Services\VenueBookingActionState;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;

final readonly class GetBookingDetails
{
    public function __construct(private VenueBookingAuthorization $authorization, private VenueBookingActionState $actionState, private VenueCommercialAccess $commercialAccess) {}

    /** @return array<string, mixed> */
    public function handle(VenueBooking $booking, Actor $actor): array
    {
        $booking->loadMissing(['venue', 'requester', 'event', 'paymentAttempt', 'extensionRequests']);
        $this->authorization->assertCanView($actor, $booking, $booking->venue);
        $actions = $this->actionState->for($booking, $actor);
        $isRequester = $actor->user_id === $booking->requester_user_id;

        $includeFinancial = $isRequester || ($actor->user !== null
            && $this->commercialAccess->allows($actor->user, $booking->venue, VenuePermissionEnum::VIEW_PAYMENTS));

        return $this->serialize($booking, $actions, $this->actionState->primary($actions, $isRequester), $includeFinancial);
    }

    /** @param array<string, array{allowed: bool, reason: string|null}> $actions
     * @return array<string, mixed>
     */
    public function serialize(VenueBooking $booking, array $actions, ?string $primaryAction, bool $includeFinancial): array
    {
        return [
            'booking_id' => $booking->public_id,
            'version' => $booking->optimistic_version,
            'status' => $booking->status->value,
            'status_label' => $booking->status->label(),
            'venue' => ['id' => $booking->venue_id, 'name' => $booking->venue->name],
            'scope' => $booking->scope?->value,
            'starts_at' => $booking->starts_at->utc()->toIso8601String(),
            'ends_at' => $booking->ends_at->utc()->toIso8601String(),
            'hold_expires_at' => $booking->hold_expires_at?->utc()->toIso8601String(),
            'effective_protection_until' => $booking->effective_protection_until?->utc()->toIso8601String(),
            'primary_action' => $primaryAction,
            'actions' => $actions,
            'payment' => $includeFinancial ? [
                'state' => $booking->payment_state->value,
                'amount_minor' => data_get($booking->quote_snapshot, 'pricing.amount_minor'),
                'currency' => data_get($booking->quote_snapshot, 'pricing.currency'),
            ] : null,
            'payment_state' => $includeFinancial ? $booking->payment_state->value : null,
            'payment_window_expires_at' => $includeFinancial ? $booking->payment_window_expires_at?->utc()->toIso8601String() : null,
            'payment_attempt' => ! $includeFinancial || $booking->paymentAttempt === null ? null : [
                'id' => $booking->paymentAttempt->public_id,
                'amount_minor' => $booking->paymentAttempt->amount_minor,
                'currency' => $booking->paymentAttempt->currency,
                'method' => $booking->paymentAttempt->method,
                'status' => $booking->paymentAttempt->status->value,
            ],
            'extensions' => $booking->extensionRequests->map(fn ($extension): array => [
                'id' => $extension->public_id,
                'status' => $extension->status->value,
                'previous_deadline_at' => $extension->previous_deadline_at->utc()->toIso8601String(),
                'requested_until' => $extension->requested_until->utc()->toIso8601String(),
                'reason' => $extension->reason,
                'decision_reason' => $extension->decision_reason,
            ])->values()->all(),
            'event_id' => $booking->event_id,
            'event_route_id' => $booking->event?->routeIdentifier(),
            'server_time' => now()->utc()->toIso8601String(),
            'updated_at' => $booking->updated_at->utc()->toIso8601String(),
        ];
    }
}
