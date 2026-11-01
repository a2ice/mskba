<?php

namespace App\Modules\Contract\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Contract\Domain\Models\ContractTemplate;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum as EventRight;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\League\Domain\Enums\LeaguePermissionEnum as LeagueRight;
use App\Modules\League\Domain\Enums\SeasonPermissionEnum as SeasonRight;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum as SectionRight;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum as TeamRight;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum as TournamentRight;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum as VenueRight;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ContractTemplateService
{
    public function __construct(private EntityContractManager $contracts) {}

    /** @return list<array{name:string,permissions:list<string>}> */
    public function builtIn(Scope $scope): array
    {
        $presets = match ($scope) {
            Scope::LEAGUE => [
                ['Спортивный координатор', [LeagueRight::MANAGE_TEAMS, LeagueRight::MANAGE_TOURNAMENTS]],
                ['Редактор лиги', [LeagueRight::EDIT]],
            ],
            Scope::SEASON => [
                ['Координатор сезона', [SeasonRight::MANAGE_TEAMS, SeasonRight::MANAGE_TOURNAMENTS]],
                ['Редактор сезона', [SeasonRight::EDIT]],
            ],
            Scope::TOURNAMENT => [
                ['Секретарь турнира', [TournamentRight::MANAGE_GAMES, TournamentRight::MANAGE_STATUS]],
                ['Редактор турнира', [TournamentRight::MANAGE_DESCRIPTION]],
            ],
            Scope::TEAM => [
                ['Менеджер состава', [TeamRight::MANAGE_ROSTER, TeamRight::INVITE_MEMBERS]],
                ['Ответственный за оформление', [TeamRight::EDIT_SETTINGS]],
            ],
            Scope::VENUE => [
                ['Ответственный за расписание', [VenueRight::EDIT_SCHEDULE]],
                ['Редактор площадки', [VenueRight::EDIT, VenueRight::VIEW]],
            ],
            Scope::SPORTS_SECTION => [
                ['Тренер', [SectionRight::MANAGE_SESSIONS, SectionRight::MANAGE_TRAINEES]],
                ['Ответственный за контакты', [SectionRight::MANAGE_CONTACTS]],
            ],
            Scope::EVENT => [
                ['Статистик мероприятия', [EventRight::MANAGE_MINI_GAME_SCORE, EventRight::MANAGE_MINI_GAME_STATISTICS]],
                ['Помощник организатора', [EventRight::UPDATE_EVENT, EventRight::MANAGE_PARTICIPANTS]],
            ],
            default => [],
        };

        return array_map(static fn (array $preset): array => [
            'name' => $preset[0],
            'permissions' => array_map(static fn (\BackedEnum $right): string => $right->value, $preset[1]),
        ], $presets);
    }

    /** @return Collection<int, ContractTemplate> */
    public function available(User $user, Scope $scope, int $scopeId): Collection
    {
        $user = $user->canonical();

        return ContractTemplate::query()
            ->where('scope_type', $scope->value)
            ->where(function ($query) use ($user, $scopeId): void {
                $query->whereIn('owner_user_id', $user->identityIds())
                    ->orWhere(fn ($q) => $q->where('shared', true)->where('scope_id', $scopeId));
            })
            ->orderBy('name')
            ->get();
    }

    /** @param list<string> $permissions */
    public function save(User $user, Scope $scope, int $scopeId, string $name, array $permissions, bool $shared, ?ContractTemplate $template = null): ContractTemplate
    {
        $user = $user->canonical();
        $manage = $this->contracts->managePermission($scope);
        abort_unless($manage !== null && $this->contracts->allows($user, $scope, $scopeId, $manage), 403);

        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            throw ValidationException::withMessages(['template_name' => 'Укажите название от 2 до 150 символов.']);
        }

        $allowed = $this->contracts->effectivePermissions($user, $scope, $scopeId);
        if ($permissions === [] || count($permissions) !== count(array_unique($permissions))
            || array_diff($permissions, $this->contracts->allPermissions($scope)) !== []
            || array_diff($permissions, $allowed) !== []) {
            throw ValidationException::withMessages(['template_permissions' => 'Можно сохранять только доступные вам полномочия.']);
        }

        if ($template !== null) {
            abort_unless(in_array((int) $template->owner_user_id, $user->identityIds(), true)
                && $template->scope_type === $scope
                && (! $template->shared || (int) $template->scope_id === $scopeId), 403);
        }

        return DB::transaction(function () use ($user, $scope, $scopeId, $name, $permissions, $shared, $template): ContractTemplate {
            $template ??= new ContractTemplate();
            $template->fill([
                'owner_user_id' => $user->id,
                'scope_type' => $scope->value,
                'scope_id' => $shared ? $scopeId : null,
                'name' => $name,
                'permissions' => array_values($permissions),
                'shared' => $shared,
            ]);
            $template->save();

            return $template;
        });
    }

    public function delete(User $user, Scope $scope, int $scopeId, ContractTemplate $template): void
    {
        $user = $user->canonical();
        $manage = $this->contracts->managePermission($scope);
        abort_unless($manage !== null && $this->contracts->allows($user, $scope, $scopeId, $manage), 403);
        abort_unless(in_array((int) $template->owner_user_id, $user->identityIds(), true)
            && $template->scope_type === $scope
            && (! $template->shared || (int) $template->scope_id === $scopeId), 403);
        $template->delete();
    }
}
