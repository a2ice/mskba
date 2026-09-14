<?php

namespace App\Modules\SportsSection\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Application\Services\SportsSectionAccess;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Support\Facades\DB;

final readonly class ManageSportsSectionTeamHandler
{
    public function __construct(
        private SportsSectionAccess $sectionAccess,
        private TeamManagementAccess $teamAccess,
    ) {}

    public function link(SportsSection $section, Team $team, User $user, Actor $actor): void
    {
        $user = $user->canonical();

        DB::transaction(function () use ($section, $team, $user, $actor): void {
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            $team = Team::query()->lockForUpdate()->findOrFail($team->id);

            if (! $this->sectionAccess->allows($user, $section, SportsSectionPermissionEnum::MANAGE)) {
                throw new SportsSectionException('Недостаточно прав для изменения связей секции.');
            }
            if ($team->isTemporary()) {
                throw new SportsSectionException('К секции можно привязать только постоянную команду.');
            }
            if (! $this->teamAccess->allows($team, $actor, TeamPermissionEnum::EDIT_SETTINGS)) {
                throw new SportsSectionException('Для связи нужно право редактировать настройки команды.');
            }

            $section->teams()->syncWithoutDetaching([$team->id]);
        });
    }

    public function unlink(SportsSection $section, Team $team, User $user): void
    {
        $user = $user->canonical();

        DB::transaction(function () use ($section, $team, $user): void {
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            if (! $this->sectionAccess->allows($user, $section, SportsSectionPermissionEnum::MANAGE)) {
                throw new SportsSectionException('Недостаточно прав для изменения связей секции.');
            }

            $section->teams()->detach($team->id);
        });
    }
}
