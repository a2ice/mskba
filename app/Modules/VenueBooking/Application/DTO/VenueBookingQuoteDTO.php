<?php

namespace App\Modules\VenueBooking\Application\DTO;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use Carbon\CarbonImmutable;

final readonly class VenueBookingQuoteDTO
{
    public function __construct(
        public string $publicId,
        public int $policyVersionId,
        public int $policyVersion,
        public VenueBookingScopeEnum $scope,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public int $amountMinor,
        public string $currency,
        public bool $requiresPayment,
        public int $holdDurationMinutes,
        public ?int $paymentWindowMinutes,
        public CarbonImmutable $validUntil,
    ) {}
}
