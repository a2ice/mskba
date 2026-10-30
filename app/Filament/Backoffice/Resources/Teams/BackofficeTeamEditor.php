<?php

namespace App\Filament\Backoffice\Resources\Teams;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Application\Services\TeamNameAllocator;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Event\Domain\Models\GameSide;
use App\Support\Text\CyrillicTransliterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class BackofficeTeamEditor
{
    private const COLOR_KEYS = ['home_primary', 'home_secondary', 'away_primary', 'away_secondary'];

    /** @return array<string, mixed> */
    private function validate(array $data, bool $creating): array
    {
        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:140'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::enum(TeamStatusEnum::class)],
            'accepts_join_requests' => ['boolean'],
            'accepts_competition_invitations' => ['boolean'],
            'sport_types' => ['required', 'array', 'min:1'],
            'sport_types.*' => ['string', 'distinct', Rule::enum(TeamSportTypeEnum::class)],
            'colors' => ['nullable', 'array'],
        ];
        if ($creating) {
            $rules['owner_user_id'] = ['required', 'integer', Rule::exists('users', 'id')->whereNull('canonical_user_id')->whereNull('deleted_at')];
        }
        foreach (self::COLOR_KEYS as $key) {
            $rules["colors.$key"] = ['nullable', 'string', 'regex:/\A#[a-fA-F0-9]{6}\z/D'];
        }
        $data = Validator::make($data, $rules)->validate();
        $data['name'] = trim(preg_replace('/\s+/u', ' ', $data['name']) ?? $data['name']);
        $data['colors'] = array_filter(
            array_map(static fn ($color) => $color ? strtolower($color) : null,
                array_intersect_key($data['colors'] ?? [], array_flip(self::COLOR_KEYS))),
            static fn ($value) => $value !== null,
        ) ?: null;
        return $data;
    }

    public function create(array $data): Team
    {
        abort_unless(TeamResource::authorized(), 403);
        $actor = app(CurrentActorResolver::class)->resolve(auth('web')->user(), null);
        abort_if($actor?->user_id === null, 403);
        // The creator is the default owner in both the backoffice and public flow.
        // An explicitly selected different owner in Filament always takes precedence.
        $data['owner_user_id'] ??= $actor->user?->canonical()->id;
        $data = $this->validate($data, true);
        return DB::transaction(function () use ($data, $actor): Team {
            $owner = User::query()->whereKey($data['owner_user_id'])
                ->whereNull('canonical_user_id')->lockForUpdate()->firstOrFail();
            $names = app(TeamNameAllocator::class)->allocate($data['name'], $owner->id);
            unset($names['has_duplicate']);
            $slug = Str::slug(app(CyrillicTransliterator::class)->transliterate($names['name'])) ?: 'team';
            $alias = $slug;
            for ($i = 2; Team::withTrashed()->where('alias', $alias)->exists(); $i++) {
                $alias = $slug.'-'.$i;
            }
            $team = Team::query()->create([
                ...$names, 'alias' => $alias,
                'created_by_actor_id' => $actor->id,
                'description' => $data['description'] ?? null,
                'colors' => $data['colors'],
                'status' => $data['status'],
                'accepts_join_requests' => (bool) ($data['accepts_join_requests'] ?? false),
                'accepts_competition_invitations' => (bool) ($data['accepts_competition_invitations'] ?? false),
            ]);
            foreach ($data['sport_types'] as $sport) {
                $team->sportProfiles()->create(['sport_type' => $sport]);
            }
            $contract = Contract::query()->create([
                'family' => ContractFamilyEnum::MEMBERSHIP,
                'name' => 'Владелец команды «'.$team->name.'»',
                'status' => ContractStatusEnum::ACTIVE,
                'assigned_by' => $actor->user_id,
                'assigned_at' => now(),
                'assigner' => UserParticipationRoleAssignerEnum::USER,
            ]);
            $contract->membership()->create([
                'scope_type' => ContractMembershipScopeTypeEnum::TEAM,
                'scope_id' => $team->id,
                'user_id' => $owner->id,
                'access_level' => TeamMembershipAccessLevelEnum::OWNER->value,
                'sport_roles' => [],
                'invitation_status' => TeamInvitationStatusEnum::ACCEPTED,
            ]);
            return $team;
        });
    }

    public function update(Team $team, array $data): Team
    {
        abort_unless(TeamResource::canEdit($team), 403);
        $data = $this->validate($data, false);
        try {
            return DB::transaction(function () use ($team, $data): Team {
                $team = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
                $owner = $team->memberships()->where('access_level', TeamMembershipAccessLevelEnum::OWNER->value)
                    ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
                    ->first();
                if ($data['status'] === TeamStatusEnum::ACTIVE->value && $owner === null) {
                    throw ValidationException::withMessages(['status' => 'Для активной команды необходим действующий владелец.']);
                }
                $name = app(TeamNameAllocator::class);
                if ($data['status'] === TeamStatusEnum::ACTIVE->value) {
                    if ($team->status !== TeamStatusEnum::ACTIVE || $name->normalize($data['name']) !== $team->normalized_name) {
                        $creator = (int) ($team->createdByActor?->user_id ?? 0);
                        $names = $name->allocate($data['name'], $creator, $team);
                        unset($names['has_duplicate']);
                    } else {
                        $base = $name->clean($data['name']);
                        $names = ['base_name' => $base, 'name' => $team->name_sequence > 1
                            ? $base.' №'.$team->name_sequence : $base];
                    }
                } else {
                    $base = $name->clean($data['name']);
                    $names = ['base_name' => $base, 'normalized_name' => $name->normalize($base),
                        'name_sequence' => null, 'name' => $base];
                }
                $team->update([
                    ...$names, 'description' => $data['description'] ?? null,
                    'colors' => $data['colors'], 'status' => $data['status'],
                    'accepts_join_requests' => (bool) ($data['accepts_join_requests'] ?? false),
                    'accepts_competition_invitations' => (bool) ($data['accepts_competition_invitations'] ?? false),
                ]);
                $existing = $team->sportProfiles()->get();
                foreach ($existing as $profile) {
                    if (in_array($profile->sport_type->value, $data['sport_types'], true)) {
                        continue;
                    }
                    if ($profile->lineupMembers()->exists()) {
                        throw ValidationException::withMessages(['sport_types' => 'Перед отключением формата удалите связанный стартовый состав.']);
                    }
                    $profile->delete();
                }
                foreach ($data['sport_types'] as $sport) {
                    $team->sportProfiles()->firstOrCreate(['sport_type' => $sport]);
                }
                return $team->refresh();
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }
    }

    public function delete(Team $team): void
    {
        abort_unless(TeamResource::canDelete($team), 403);
        DB::transaction(function () use ($team): void {
            $team = Team::query()->lockForUpdate()->findOrFail($team->id);
            if (GameSide::query()->where('team_id', $team->id)->exists()) {
                throw ValidationException::withMessages(['team' =>
                    'Команду с историей игр нельзя удалять. Переведите её в архив.']);
            }
            $team->update(['status' => TeamStatusEnum::ARCHIVED]);
            $team->delete();
        });
    }

    public function restore(Team $team): void
    {
        abort_unless(TeamResource::authorized() && $team->trashed(), 403);
        $team->restore();
    }
}
