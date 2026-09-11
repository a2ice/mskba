<?php

namespace App\Modules\SportsSection\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\SportsSection\Domain\Enums\SectionContactSourceEnum;
use App\Modules\SportsSection\Domain\Enums\SectionPricingTypeEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionStatusEnum;
use App\Modules\SportsSection\Domain\Enums\TrainingModeEnum;
use App\Modules\SportsSection\Infrastructure\Database\Factories\SportsSectionFactory;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'created_by_actor_id', 'name', 'alias', 'description', 'status', 'training_mode',
    'game_format', 'primary_venue_id', 'primary_venue_court_id', 'pricing_type',
    'single_session_price_minor', 'currency', 'contact_source', 'contact_notes',
    'head_coach_membership_id',
])]
class SportsSection extends Model
{
    /** @use HasFactory<SportsSectionFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected static function newFactory(): SportsSectionFactory
    {
        return SportsSectionFactory::new();
    }

    public function getRouteKeyName(): string
    {
        return 'alias';
    }

    public function creatorActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'created_by_actor_id');
    }

    public function primaryVenue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'primary_venue_id');
    }

    public function primaryVenueCourt(): BelongsTo
    {
        return $this->belongsTo(VenueCourt::class, 'primary_venue_court_id');
    }

    public function headCoachMembership(): BelongsTo
    {
        return $this->belongsTo(ContractMembership::class, 'head_coach_membership_id');
    }

    public function coachMemberships(): HasMany
    {
        return $this->hasMany(ContractMembership::class, 'scope_id')
            ->where('scope_type', ContractMembershipScopeTypeEnum::SPORTS_SECTION->value);
    }

    public function traineeMemberships(): HasMany
    {
        return $this->hasMany(SectionTraineeMembership::class);
    }

    public function pricingPlans(): HasMany
    {
        return $this->hasMany(SectionPricingPlan::class);
    }

    public function trainingSessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }

    public function contacts(): MorphMany
    {
        return $this->morphMany(Contact::class, 'contactable');
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function featuredMedia(): MorphOne
    {
        return $this->morphOne(Media::class, 'mediable')
            ->where('collection', 'sports_section_gallery')
            ->where('is_featured', true);
    }

    protected function casts(): array
    {
        return [
            'status' => SportsSectionStatusEnum::class,
            'training_mode' => TrainingModeEnum::class,
            'game_format' => GameFormatEnum::class,
            'pricing_type' => SectionPricingTypeEnum::class,
            'contact_source' => SectionContactSourceEnum::class,
            'single_session_price_minor' => 'integer',
        ];
    }
}
