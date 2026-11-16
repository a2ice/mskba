<?php

namespace App\Modules\VenueBooking\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['invoice_id', 'actor_id', 'action', 'old_status', 'new_status', 'note'])]
final class VenueRentalInvoiceEvent extends Model
{
    public $timestamps = false;
}
