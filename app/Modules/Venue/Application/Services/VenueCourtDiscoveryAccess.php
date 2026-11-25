<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;

/**
 * Public catalogue discovery uses the effective conditions of actual courts.
 * Unknown payment conditions stay unknown rather than becoming "free".
 */
final readonly class VenueCourtDiscoveryAccess
{
    public function __construct(private EffectiveCourtPolicy $effectivePolicies) {}

    /**
     * @return array{free: array<int, array<string, mixed>>, paid: array<int, array<string, mixed>>, approval: array<int, array<string, mixed>>, unknown: array<int, array<string, mixed>>}
     */
    public function classify(Venue $venue, ?VenueBookingPolicy $policy = null, bool $includeClosed = false, bool $configuredRentalOnly = false): array
    {
        $categories = ['free' => [], 'paid' => [], 'approval' => [], 'unknown' => []];

        foreach ($venue->courts as $court) {
            if (! $court instanceof VenueCourt) {
                continue;
            }

            $effective = $this->effectivePolicies->resolve($venue, $court, $policy);
            if (! $includeClosed && ! $effective['is_open']) {
                continue;
            }

            if ($configuredRentalOnly && ! $this->effectivePolicies->hasConfiguredRental($court, $effective)) {
                continue;
            }

            // EffectiveCourtPolicy resolves absent payment settings to false for
            // booking compatibility; discovery must preserve an unknown state.
            $payment = $court->requires_payment
                ?? $policy?->requires_payment
                ?? $venue->requires_payment;

            $status = $venue->operational_status === VenueOperationalStatusEnum::ACTIVE
                ? ($court->operational_status ?? $venue->operational_status)
                : $venue->operational_status;
            $photo = $court->relationLoaded('media')
                ? $court->media->where('collection', 'gallery')
                    ->sort(fn ($a, $b): int =>
                        ((int) $b->is_featured <=> (int) $a->is_featured)
                        ?: ((int) $a->sort_order <=> (int) $b->sort_order)
                        ?: ((int) $a->id <=> (int) $b->id))->first()
                : null;

            $item = [
                'court_id' => (int) $court->id,
                'name' => $court->publicName(),
                'environment' => $court->environment?->value,
                'type_label' => $court->environment?->label(),
                'operational_status' => $status->value,
                'operational_status_label' => $status->label(),
                'is_open' => (bool) $effective['is_open'],
                'requires_payment' => $payment,
                'sort_order' => (int) $court->sort_order,
                'short_description' => $court->short_description,
                'image_url' => $photo?->publicUrl(),
                'url' => route('venues.courts.show', [
                    $venue->routeIdentifier(), $court->routeIdentifier(),
                ]),
                'requires_booking' => (bool) $effective['requires_booking'],
                'requires_booking_approval' => (bool) $effective['requires_booking_approval'],
            ];

            if ($payment === null) {
                $categories['unknown'][] = $item;
            } else {
                $categories[$payment ? 'paid' : 'free'][] = $item;
            }

            if ($effective['requires_booking_approval']) {
                $categories['approval'][] = $item;
            }
        }

        return $categories;
    }

    /** Select one actual court; prefer an open match, then stable display order. */
    public static function select(
        array $categories,
        ?string $environment = null,
        ?bool $requiresPayment = null,
        ?bool $requiresApproval = null,
        ?string $status = null,
        bool $allowUnconstrained = false,
    ): ?array {
        if (! $allowUnconstrained && $environment === null && $requiresPayment === null && $requiresApproval === null) {
            return null;
        }

        $courts = array_merge($categories['free'] ?? [], $categories['paid'] ?? [], $categories['unknown'] ?? []);
        $matches = array_values(array_filter($courts, fn (array $court): bool =>
            ($environment === null || $court['environment'] === $environment)
            && ($requiresPayment === null || $court['requires_payment'] === $requiresPayment)
            && ($requiresApproval === null || $court['requires_booking_approval'] === $requiresApproval)
            && ($status === null || $court['operational_status'] === $status)));
        usort($matches, fn (array $a, array $b): int =>
            ($b['is_open'] <=> $a['is_open'])
            ?: ($a['sort_order'] <=> $b['sort_order'])
            ?: ($a['court_id'] <=> $b['court_id']));

        return $matches[0] ?? null;
    }

    public static function paymentFilter(?string $access): ?bool
    {
        return match ($access) {
            'free' => false,
            'paid' => true,
            default => null,
        };
    }
}
