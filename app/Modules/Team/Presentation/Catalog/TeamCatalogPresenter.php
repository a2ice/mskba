<?php

namespace App\Modules\Team\Presentation\Catalog;

use App\Modules\Team\Domain\Enums\TeamLineupAssignmentEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Enums\TeamVenueRelationTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final class TeamCatalogPresenter
{
    /**
     * @param  EloquentCollection<int, Team>|Collection<int, Team>  $teams
     * @return Collection<int, array<string, mixed>>
     */
    public function present(Collection $teams): Collection
    {
        if ($teams instanceof EloquentCollection && $teams->isNotEmpty()) {
            $teams->load([
                'venueRelations' => fn ($relations) => $relations
                    ->where('relation_type', TeamVenueRelationTypeEnum::CONFIRMED->value)
                    ->whereHas('venue', fn ($venues) => $venues->where('status', VenueStatusEnum::CONFIRMED->value))
                    ->with('venue.location.address'),
            ]);
        }

        return $teams->mapWithKeys(fn (Team $team): array => [
            $team->id => $this->presentTeam($team),
        ]);
    }

    /** @return array<string, mixed> */
    private function presentTeam(Team $team): array
    {
        $coach = $team->memberships->first(
            fn ($membership): bool => $membership->hasSportRole(TeamMemberTypeEnum::COACH),
        );
        $captain = $team->memberships->first(fn ($membership): bool => (bool) $membership->is_captain);
        $activePlayerIds = $team->memberships
            ->filter(fn ($membership): bool => $membership->hasSportRole(TeamMemberTypeEnum::PLAYER))
            ->pluck('id');
        $rosterComplete = $team->sportProfiles->every(function ($profile) use ($activePlayerIds): bool {
            $required = $profile->sport_type === TeamSportTypeEnum::STREETBALL ? 3 : 5;

            return $profile->lineupMembers
                ->where('assignment', TeamLineupAssignmentEnum::STARTER)
                ->whereIn('contract_membership_id', $activePlayerIds)
                ->count() === $required;
        });
        $memberCount = (int) ($team->active_memberships_count ?? $team->memberships->count());

        return [
            'id' => $team->id,
            'name' => $team->name,
            'url' => route('teams.show', $team->routeIdentifier()),
            'logo_url' => $team->logo?->publicUrl() ?: asset('images/team-placeholder.webp'),
            'description' => $team->description ?: 'Описание команды пока не добавлено.',
            'status' => [
                'value' => $team->status->value,
                'label' => $team->status->label(),
                'icon' => $this->statusIcon($team->status),
            ],
            'sports' => $team->sportProfiles->map(fn ($profile): array => [
                'value' => $profile->sport_type->value,
                'label' => $profile->sport_type->label(),
                'short_label' => $profile->sport_type->shortLabel(),
            ])->values()->all(),
            'roster_complete' => $rosterComplete,
            'hiring_count' => (int) ($team->active_hiring_positions_count ?? 0),
            'member_count' => $memberCount,
            'member_count_text' => $this->memberCountText($memberCount),
            'coach_name' => $this->memberName($coach),
            'captain_name' => $this->memberName($captain),
            'confirmed_venues' => $team->venueRelations
                ->map(function ($relation): array {
                    $venue = $relation->venue;
                    $address = $venue?->raw_address ?: $venue?->location?->address?->full_address;

                    return [
                        'id' => $venue?->id,
                        'name' => $venue?->name ?: 'Площадка',
                        'address' => $address,
                        'latitude' => $venue?->location?->address?->latitude,
                        'longitude' => $venue?->location?->address?->longitude,
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    private function memberName($membership): string
    {
        if ($membership === null) {
            return '—';
        }

        $profile = $membership->user->profile;

        return trim(implode(' ', array_filter([$profile?->first_name, $profile?->last_name])))
            ?: $membership->user->username
            ?: '—';
    }

    private function memberCountText(int $count): string
    {
        $modulo100 = $count % 100;
        $modulo10 = $count % 10;
        $label = $modulo100 >= 11 && $modulo100 <= 14
            ? 'участников'
            : match ($modulo10) {
                1 => 'участник',
                2, 3, 4 => 'участника',
                default => 'участников',
            };

        return "{$count} {$label}";
    }

    private function statusIcon(TeamStatusEnum $status): string
    {
        return match ($status) {
            TeamStatusEnum::ACTIVE => 'ti-circle-check',
            TeamStatusEnum::DRAFT => 'ti-pencil',
            TeamStatusEnum::BLOCKED => 'ti-lock',
            TeamStatusEnum::ARCHIVED => 'ti-archive',
        };
    }
}
