<?php

namespace App\Modules\VenueBooking\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name', 'scope', 'venue_id', 'owner_user_id', 'title', 'description',
    'payment_methods', 'payment_instructions', 'due_minutes',
    'auto_send', 'enabled', 'priority',
])]
final class VenueRentalInvoiceTemplate extends Model
{
    protected function casts(): array
    {
        return [
            'payment_methods' => 'array',
            'due_minutes' => 'integer',
            'priority' => 'integer',
            'auto_send' => 'boolean',
            'enabled' => 'boolean',
        ];
    }
}
