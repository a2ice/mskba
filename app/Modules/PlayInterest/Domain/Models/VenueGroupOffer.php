<?php

namespace App\Modules\PlayInterest\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class VenueGroupOffer extends Model
{
    protected $fillable = [
        'venue_id', 'group_key', 'created_by_user_id', 'title', 'description',
        'code_ciphertext', 'code_hash', 'status', 'discount_type', 'discount_value',
        'currency', 'scope', 'venue_court_id', 'minimum_amount_minor',
        'valid_from', 'valid_until', 'max_total_uses', 'max_uses_per_account',
        'terms_version',
    ];

    protected $hidden = ['code_ciphertext', 'code_hash'];

    protected function casts(): array
    {
        return [
            'code_ciphertext' => 'encrypted',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'discount_value' => 'integer',
            'minimum_amount_minor' => 'integer',
            'max_total_uses' => 'integer',
            'max_uses_per_account' => 'integer',
            'used_count' => 'integer',
            'terms_version' => 'integer',
        ];
    }

    public static function groupKey(Venue $venue): string
    {
        return 'venue:'.$venue->id;
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(VenueGroupOfferAudit::class, 'offer_id');
    }

    public function isCurrentlyPublished(): bool
    {
        return $this->status === 'active'
            && $this->valid_from->lessThanOrEqualTo(now())
            && ($this->valid_until === null || $this->valid_until->greaterThan(now()));
    }
}
