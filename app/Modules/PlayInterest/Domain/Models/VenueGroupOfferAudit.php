<?php

namespace App\Modules\PlayInterest\Domain\Models;

use Illuminate\Database\Eloquent\Model;

final class VenueGroupOfferAudit extends Model
{
    public $timestamps = false;

    protected $fillable = ['actor_user_id', 'action', 'previous_status', 'next_status', 'terms_version', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
