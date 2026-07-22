<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum;
use App\Modules\Moderation\Domain\Enums\ModerationTypeEnum;
use App\Modules\Moderation\Domain\Models\ModerationRequest;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Infrastructure\Database\Factories\VenueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'created_by_actor_id',
    'location_id',
    'canonical_venue_id',
    'name',
    'alias',
    'type',
    'requires_payment',
    'requires_booking_approval',
    'status',
    'operational_status',
    'status_info',
    'short_description',
    'full_description',
    'raw_address',
    'content_version',
])]
#[Hidden([])]
class Venue extends Model
{
    /** @use HasFactory<VenueFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'requires_payment' => false,
        'requires_booking_approval' => false,
        'content_version' => 0,
        'operational_status' => VenueOperationalStatusEnum::ACTIVE->value,
    ];

    protected static function newFactory(): VenueFactory
    {
        return VenueFactory::new();
    }

    public function allowsDetailsEditing(): bool
    {
        return ! $this->trashed();
    }

    public function allowsOperationalChanges(): bool
    {
        return ! $this->trashed() && $this->status !== VenueStatusEnum::BLOCKED;
    }

    public function hasFreeAccess(): bool
    {
        return ! $this->requires_payment && ! $this->requires_booking_approval;
    }

    public function routeIdentifier(): string
    {
        return $this->id.'-'.$this->alias;
    }

    /**
     * @param  Builder<Venue>  $query
     * @return Builder<Venue>
     */
    public function scopeWhereRouteIdentifier(Builder $query, string $identifier): Builder
    {
        if (preg_match('/^(\d+)-/', $identifier, $matches) === 1) {
            return $query->whereKey((int) $matches[1]);
        }

        return $query->where('alias', $identifier);
    }

    public function hasPendingModerationRequest(): bool
    {
        return $this->moderationRequests()
            ->where('status', ModerationRequestStatusEnum::PENDING->value)
            ->exists();
    }

    public function memberships(): HasMany
    {
        return $this
            ->hasMany(ContractMembership::class, 'scope_id')
            ->where('scope_type', ContractMembershipScopeTypeEnum::VENUE->value);
    }

    public function creatorActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'created_by_actor_id');
    }

    public function canonicalVenue(): BelongsTo
    {
        return $this->belongsTo(self::class, 'canonical_venue_id');
    }

    public function duplicateVenues(): HasMany
    {
        return $this->hasMany(self::class, 'canonical_venue_id');
    }

    public function duplicateCandidates(): HasMany
    {
        return $this->hasMany(VenueDuplicate::class);
    }

    public function duplicateOfCandidates(): HasMany
    {
        return $this->hasMany(VenueDuplicate::class, 'duplicate_venue_id');
    }

    public function moderationRequests(): HasMany
    {
        return $this
            ->hasMany(ModerationRequest::class, 'subject_id')
            ->where('type', ModerationTypeEnum::VENUE->value);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function contacts(): MorphMany
    {
        return $this->morphMany(Contact::class, 'contactable');
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(VenueRevision::class);
    }

    public function draftRevision(): HasOne
    {
        return $this->hasOne(VenueRevision::class)->whereNull('applied_at')->latestOfMany();
    }

    public function amenities(): BelongsToMany
    {
        return $this
            ->belongsToMany(Amenity::class, 'venue_amenities')
            ->withPivot(['note', 'deleted_at'])
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    public function schedule(): HasOne
    {
        return $this->hasOne(VenueSchedule::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(VenueReview::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(VenueTag::class);
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
            'requires_payment' => 'boolean',
            'requires_booking_approval' => 'boolean',
            'status' => VenueStatusEnum::class,
            'operational_status' => VenueOperationalStatusEnum::class,
            'canonical_venue_id' => 'integer',
            'content_version' => 'integer',
        ];
    }
}
