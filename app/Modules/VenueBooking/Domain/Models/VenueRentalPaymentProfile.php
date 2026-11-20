<?php

namespace App\Modules\VenueBooking\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['owner_user_id', 'venue_id', 'label', 'details'])]
final class VenueRentalPaymentProfile extends Model
{
    protected function casts(): array
    {
        return ['details' => 'encrypted:array'];
    }
}
