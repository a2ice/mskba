<?php

namespace App\Modules\VenueBooking\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'public_id', 'number', 'venue_booking_id', 'template_id', 'kind',
    'issuer_kind', 'payer_kind', 'document_kind', 'issuer_user_id', 'payer_user_id', 'issued_by_actor_id',
    'reviewed_by_actor_id', 'status', 'currency', 'amount_minor', 'title',
    'description', 'payment_methods', 'payment_instructions', 'settlement_basis',
    'settlement_note', 'issued_at', 'due_at', 'settled_at', 'quote_snapshot',
])]
final class VenueRentalInvoice extends Model
{
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class, 'venue_booking_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(VenueRentalInvoiceEvent::class, 'invoice_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VenueRentalInvoicePayment::class, 'invoice_id');
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'payment_methods' => 'array',
            'quote_snapshot' => 'array',
            'issued_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
        ];
    }
}
