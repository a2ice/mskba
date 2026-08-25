<?php

namespace App\Modules\VenueBooking\Application\Services;

final class VenueBookingCommandContext
{
    private ?int $receiptId = null;

    private ?string $correlationId = null;

    public function enter(int $receiptId, string $correlationId): void
    {
        $this->receiptId = $receiptId;
        $this->correlationId = $correlationId;
    }

    public function leave(): void
    {
        $this->receiptId = null;
        $this->correlationId = null;
    }

    public function receiptId(): ?int
    {
        return $this->receiptId;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }
}
