<?php

namespace App\Modules\VenueBooking\Application\Payments;

use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;

final readonly class PaymentProviderIntent
{
    /** @param array<string, scalar|null> $safeMetadata */
    public function __construct(public string $reference, public VenueBookingPaymentState $status, public array $safeMetadata = []) {}
}
