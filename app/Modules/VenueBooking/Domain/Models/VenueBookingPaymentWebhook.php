<?php

namespace App\Modules\VenueBooking\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['provider', 'provider_event_id', 'payload_hash', 'signature_valid', 'safe_payload', 'status', 'failure_reason', 'processed_at'])]
class VenueBookingPaymentWebhook extends Model
{
    protected function casts(): array
    {
        return ['signature_valid' => 'boolean', 'safe_payload' => 'array', 'processed_at' => 'immutable_datetime'];
    }
}
