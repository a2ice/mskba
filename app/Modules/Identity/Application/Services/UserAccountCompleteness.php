<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Event\Domain\Models\EventParticipant;
use App\Modules\Identity\Domain\Models\Participation\PlayerSelfAssessment;
use App\Modules\Identity\Domain\Models\User;

final class UserAccountCompleteness
{
    /**
     * A fixed, comparable checklist, not a measure of account privileges.
     * Optional fields and activity are indicators, not account requirements.
     *
     * @return array{percent: int, filled: int, total: int, contacts: int, roles: int, events: int, teams: int}
     */
    public function summarize(User $user): array
    {
        $user->loadMissing(['profile.activeAvatar', 'playerProfile.positions', 'playerProfile.selfAssessment']);
        $profile = $user->profile;
        $player = $user->playerProfile;
        $contacts = $user->identityContactsQuery()->whereNotNull('verified_at');
        $contactTypes = (clone $contacts)->distinct()->pluck('type')->count();
        $roles = $user->participationRoles()->count();
        $ids = $user->identityIds();
        $events = EventParticipant::query()->whereIn('user_id', $ids)->distinct()->count('event_id');
        $teams = ContractMembership::query()->whereIn('user_id', $ids)
            ->where('scope_type', ContractMembershipScopeTypeEnum::TEAM->value)->distinct()->count('scope_id');

        $checks = [
            filled($user->username) && ! preg_match('/^tg_\d+(?:_[a-z0-9]+)?$/i', (string) $user->username),
            filled($profile?->first_name),
            filled($profile?->last_name),
            filled($profile?->middle_name),
            $profile?->birth_date !== null,
            $profile?->gender !== null,
            $profile?->activeAvatar !== null,
            $contactTypes > 0,
            $contactTypes > 1,
            $roles > 0,
            $player?->height_cm !== null,
            $player?->weight_kg !== null,
            $player?->body_type !== null,
            $player?->experience_started_year !== null,
            filled($player?->comment),
            $player?->positions->isNotEmpty() ?? false,
            $events > 0,
            $teams > 0,
        ];

        foreach (array_keys(PlayerSelfAssessment::SKILLS) as $skill) {
            $checks[] = $player?->selfAssessment?->getAttribute($skill) !== null;
        }

        $filled = count(array_filter($checks));

        return [
            'percent' => (int) round(100 * $filled / count($checks)),
            'filled' => $filled,
            'total' => count($checks),
            'contacts' => $contactTypes,
            'roles' => $roles,
            'events' => $events,
            'teams' => $teams,
        ];
    }
}
