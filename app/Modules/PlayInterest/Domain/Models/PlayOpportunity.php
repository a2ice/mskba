<?php

namespace App\Modules\PlayInterest\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PlayOpportunity extends Model
{
    protected $fillable = [
        'public_id', 'generated_title', 'custom_title', 'status', 'format',
        'scope_key', 'anchor_venue_id', 'common_venue_ids', 'common_time',
        'admin_user_id', 'admin_assigned_at', 'admin_source',
    ];

    protected function casts(): array
    {
        return [
            'common_venue_ids' => 'array',
            'common_time' => 'array',
            'admin_user_id' => 'integer',
            'admin_assigned_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function displayTitle(): string
    {
        return $this->custom_title ?: $this->generated_title;
    }

    public function members(): HasMany
    {
        return $this->hasMany(PlayOpportunityMember::class, 'opportunity_id');
    }

    public function elections(): HasMany
    {
        return $this->hasMany(PlayGroupElection::class, 'opportunity_id');
    }

    public function venuePreferences(): HasMany
    {
        return $this->hasMany(PlayGroupVenuePreference::class, 'opportunity_id');
    }

    public function assemblies(): HasMany
    {
        return $this->hasMany(PlayGroupAssembly::class, 'opportunity_id');
    }

    public function administrator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function anchorVenue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'anchor_venue_id');
    }

    public function memberIds(): array
    {
        return $this->members()->where('status','active')->pluck('user_id')
            ->map(fn ($id): int => (int) $id)->all();
    }

    /** @return list<int> */
    public function electorate(): array
    {
        $members = $this->members()->where('status','active')->with('user')->get();
        $ids = [];
        foreach ($members as $member) {
            $user = $member->user?->canonical();
            if ($user !== null && ! $user->trashed() && ! $user->isBlocked() && $user->isConfirmed()) {
                $ids[(int) $user->id] = true;
            }
        }
        $result = array_map('intval', array_keys($ids));
        sort($result);
        return $result;
    }

    public function hasActiveMember(User $user): bool
    {
        return in_array((int) $user->canonical()->id, $this->electorate(), true);
    }

    public function isAdministrator(?User $user): bool
    {
        return $user !== null && $this->admin_user_id !== null
            && $this->admin_user_id === (int) $user->canonical()->id
            && $this->hasActiveMember($user);
    }
}
