<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Infrastructure\Database\Factories\VenueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['created_by_user_id', 'location_id', 'name', 'alias', 'type', 'status', 'description', 'raw_address'])]
#[Hidden([])]
class Venue extends Model
{
    /** @use HasFactory<VenueFactory> */
    use HasFactory;

    protected static function newFactory(): VenueFactory
    {
        return VenueFactory::new();
    }

    public function memberships(): HasMany
    {
        return $this
            ->hasMany(ContractMembership::class, 'scope_id')
            ->where('scope_type', ContractMembershipScopeTypeEnum::VENUE->value);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function contacts(): MorphMany
    {
        return $this->morphMany(Contact::class, 'contactable');
    }

    public function getRouteKeyName(): string
    {
        return 'alias';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => VenueTypeEnum::class,
            'status' => VenueStatusEnum::class,
        ];
    }
}
