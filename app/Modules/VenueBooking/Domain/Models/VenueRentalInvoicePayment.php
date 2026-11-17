<?php

namespace App\Modules\VenueBooking\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'invoice_id', 'actor_id', 'amount_minor', 'method', 'reference',
    'note', 'verification', 'recorded_at',
])]
final class VenueRentalInvoicePayment extends Model
{
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }
}
