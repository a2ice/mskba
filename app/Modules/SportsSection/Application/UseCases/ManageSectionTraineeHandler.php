<?php

namespace App\Modules\SportsSection\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Application\Services\SportsSectionAccess;
use App\Modules\SportsSection\Application\Services\SportsSectionRules;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Enums\TraineeMembershipStatusEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SectionTraineeMembership;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use Illuminate\Support\Facades\DB;

final readonly class ManageSectionTraineeHandler
{
    public function __construct(private SportsSectionAccess $access, private SportsSectionRules $rules) {}

    public function activate(SportsSection $section, User $target, User $issuer, ?string $notes = null): SectionTraineeMembership
    {
        $target = $this->rules->assertPlayer($target);

        return DB::transaction(function () use ($section, $target, $issuer, $notes): SectionTraineeMembership {
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            $this->authorize($issuer, $section);
            $membership = SectionTraineeMembership::query()->where('sports_section_id', $section->id)
                ->whereIn('user_id', $target->identityIds())->lockForUpdate()->first();
            if ($membership === null) {
                return SectionTraineeMembership::query()->create([
                    'sports_section_id' => $section->id, 'user_id' => $target->id,
                    'status' => TraineeMembershipStatusEnum::ACTIVE, 'notes' => $notes,
                    'joined_at' => now(),
                ])->load('user.profile');
            }
            $membership->update(['user_id' => $target->id, 'status' => TraineeMembershipStatusEnum::ACTIVE, 'status_reason' => null, 'notes' => $notes ?? $membership->notes, 'left_at' => null]);

            return $membership->refresh()->load('user.profile');
        });
    }

    public function deactivate(SportsSection $section, SectionTraineeMembership $membership, User $issuer, ?string $reason): SectionTraineeMembership
    {
        return DB::transaction(function () use ($section, $membership, $issuer, $reason): SectionTraineeMembership {
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            $this->authorize($issuer, $section);
            $membership = SectionTraineeMembership::query()->lockForUpdate()->findOrFail($membership->id);
            if ($membership->sports_section_id !== $section->id) {
                throw new SportsSectionException('Тренируемый не относится к выбранной секции.');
            }
            $membership->update(['status' => TraineeMembershipStatusEnum::INACTIVE, 'status_reason' => trim((string) $reason) ?: null, 'left_at' => now()]);

            return $membership->refresh();
        });
    }

    private function authorize(User $issuer, SportsSection $section): void
    {
        if (! $this->access->allows($issuer->canonical(), $section, SportsSectionPermissionEnum::MANAGE_TRAINEES)) {
            throw new SportsSectionException('Недостаточно прав для управления составом секции.');
        }
    }
}
