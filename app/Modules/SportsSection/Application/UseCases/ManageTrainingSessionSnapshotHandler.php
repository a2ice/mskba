<?php

namespace App\Modules\SportsSection\Application\UseCases;

use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Application\Services\SportsSectionAccess;
use App\Modules\SportsSection\Application\Services\SportsSectionRules;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SectionTraineeMembership;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\SportsSection\Domain\Models\TrainingSession;

final readonly class ManageTrainingSessionSnapshotHandler
{
    public function __construct(private SportsSectionAccess $access, private SportsSectionRules $rules) {}

    public function addParticipant(SportsSection $section, TrainingSession $session, User $target, User $issuer): void
    {
        $this->authorize($section, $session, $issuer);
        $target = $this->rules->assertPlayer($target);
        $membership = SectionTraineeMembership::query()->where('sports_section_id', $section->id)->whereIn('user_id', $target->identityIds())->first();
        $session->participants()->firstOrCreate(['user_id' => $target->id], ['section_trainee_membership_id' => $membership?->id]);
    }

    public function removeParticipant(SportsSection $section, TrainingSession $session, int $participantId, User $issuer): void
    {
        $this->authorize($section, $session, $issuer);
        $session->participants()->whereKey($participantId)->firstOrFail()->delete();
    }

    public function addCoach(SportsSection $section, TrainingSession $session, ContractMembership $membership, User $issuer): void
    {
        $this->authorize($section, $session, $issuer);
        if (! $this->access->activeMemberships($section)->whereKey($membership->id)->exists()) {
            throw new SportsSectionException('Тренер не относится к выбранной секции.');
        }
        $session->coaches()->firstOrCreate(['user_id' => $membership->user_id], ['contract_membership_id' => $membership->id]);
    }

    public function removeCoach(SportsSection $section, TrainingSession $session, int $coachId, User $issuer): void
    {
        $this->authorize($section, $session, $issuer);
        $session->coaches()->whereKey($coachId)->firstOrFail()->delete();
    }

    private function authorize(SportsSection $section, TrainingSession $session, User $issuer): void
    {
        if ($session->sports_section_id !== $section->id || ! $this->access->allows($issuer->canonical(), $section, SportsSectionPermissionEnum::MANAGE_SESSIONS)) {
            throw new SportsSectionException('Недостаточно прав для изменения состава этого занятия.');
        }
    }
}
