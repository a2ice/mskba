<?php

namespace App\Filament\Backoffice\Resources\Leagues;

use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\League\Domain\Models\League;
use App\Modules\Team\Domain\Models\Team;
use App\Support\Text\CyrillicTransliterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BackofficeLeagueEditor
{
    private function validate(array $data, bool $creating): array
    {
        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:10000'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['draft', 'active', 'archived'])],
            'team_ids' => ['sometimes', 'array'],
            'team_ids.*' => ['integer', 'distinct', Rule::exists('teams', 'id')->whereNull('temporary_for_event_id')->whereNull('deleted_at')],
        ];
        if ($creating) {
            $rules['owner_user_id'] = ['required', 'integer', Rule::exists('users', 'id')->whereNull('canonical_user_id')->whereNull('deleted_at')];
        }

        return Validator::make($data, $rules)->validate();
    }

    public function create(array $data): League
    {
        abort_unless(LeagueResource::canCreate(), 403);
        $user = auth('web')->user()?->canonical();
        abort_if($user === null, 403);
        $data['owner_user_id'] ??= $user->id;
        $data = $this->validate($data, true);
        $teamIds = array_values(array_unique($data['team_ids'] ?? []));
        if ($teamIds !== []) {
            abort_unless(LeagueResource::canManageTeams(), 403);
            $this->assertTeamsEligible($teamIds);
        }
        $actor = app(CurrentActorResolver::class)->resolve($user, null);

        return DB::transaction(function () use ($data, $actor, $teamIds, $user): League {
            $name = trim($data['name']);
            $slug = Str::slug(app(CyrillicTransliterator::class)->transliterate($name)) ?: 'league';
            $league = League::query()->create([
                'name' => $name,
                'alias' => $slug.'-'.Str::lower(Str::random(10)),
                'owner_user_id' => $data['owner_user_id'],
                'created_by_actor_id' => $actor?->id,
                'description' => $data['description'] ?? null,
                'logo_path' => $data['logo_path'] ?? null,
                'status' => $data['status'],
            ]);
            $league->teams()->sync($teamIds);
            app(EntityContractManager::class)->grantOwner(
                Scope::LEAGUE, $league->id, User::query()->findOrFail($data['owner_user_id']), $user,
                'Владелец лиги «'.$league->name.'»',
            );

            return $league;
        });
    }

    public function update(League $league, array $data): League
    {
        abort_unless(LeagueResource::canEdit($league), 403);
        $data = $this->validate($data, false);
        $hasTeamIds = array_key_exists('team_ids', $data);
        if ($hasTeamIds) {
            abort_unless(LeagueResource::canManageTeams(), 403);
            $this->assertTeamsEligible($data['team_ids']);
        }

        return DB::transaction(function () use ($league, $data, $hasTeamIds): League {
            $league = League::query()->whereKey($league->id)->lockForUpdate()->firstOrFail();
            if ($hasTeamIds) {
                $removed = array_diff($league->teams()->pluck('teams.id')->all(), $data['team_ids']);
                if ($removed !== [] && DB::table('league_season_team')
                    ->join('league_seasons', 'league_seasons.id', '=', 'league_season_team.league_season_id')
                    ->where('league_seasons.league_id', $league->id)
                    ->whereIn('league_season_team.team_id', $removed)->exists()) {
                    throw ValidationException::withMessages(['team_ids' => 'Нельзя исключить команду, которая уже участвует в сезоне этой лиги.']);
                }
                $league->teams()->sync($data['team_ids']);
            }
            $league->update([
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'logo_path' => $data['logo_path'] ?? null,
                'status' => $data['status'],
            ]);

            return $league->refresh();
        });
    }

    public function archive(League $league): void
    {
        abort_unless(LeagueResource::canArchive(), 403);
        $league->update(['status' => 'archived']);
    }

    public function delete(League $league): void
    {
        abort_unless(LeagueResource::canArchive(), 403);
        DB::transaction(function () use ($league): void {
            $league = League::query()->whereKey($league->id)->lockForUpdate()->firstOrFail();
            if ($league->seasons()->withTrashed()->exists() || $league->tournaments()->withTrashed()->exists()
                || $league->games()->withTrashed()->exists() || $league->teams()->exists()) {
                throw ValidationException::withMessages(['league' => 'Лигу со связями нельзя удалить. Используйте архивирование.']);
            }
            $league->delete();
        });
    }

    private function assertTeamsEligible(array $ids): void
    {
        if (Team::query()->competitionEligible()->whereKey($ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['team_ids' => 'Выберите только существующие активные постоянные команды.']);
        }
    }
}
