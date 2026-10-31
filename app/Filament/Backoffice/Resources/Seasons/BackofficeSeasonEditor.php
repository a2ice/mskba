<?php

namespace App\Filament\Backoffice\Resources\Seasons;

use App\Modules\League\Domain\Models\League;
use App\Modules\League\Domain\Models\LeagueSeason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BackofficeSeasonEditor
{
    private function validate(array $data, bool $creating): array
    {
        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::in(['draft', 'active', 'archived'])],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'team_ids' => ['sometimes', 'array'],
            'team_ids.*' => ['integer', 'distinct', Rule::exists('teams', 'id')],
        ];
        if ($creating) {
            $rules['league_id'] = ['required', 'integer', Rule::exists('leagues', 'id')->whereNull('deleted_at')];
        }
        return Validator::make($data, $rules)->validate();
    }

    public function create(array $data): LeagueSeason
    {
        abort_unless(SeasonResource::canCreate(), 403);
        $data = $this->validate($data, true);
        $league = League::query()->findOrFail($data['league_id']);
        if ($league->status === 'archived') {
            throw ValidationException::withMessages(['league_id' => 'Нельзя создавать сезон архивной лиги.']);
        }
        $teamIds = $data['team_ids'] ?? [];
        $this->assertLeagueTeams($league, $teamIds);
        if ($teamIds !== []) {
            abort_unless(SeasonResource::canManageTeams(), 403);
        }

        return DB::transaction(function () use ($data, $league, $teamIds): LeagueSeason {
            $season = $league->seasons()->create([
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'status' => $data['status'],
            ]);
            $season->teams()->sync($teamIds);
            return $season;
        });
    }

    public function update(LeagueSeason $season, array $data): LeagueSeason
    {
        abort_unless(SeasonResource::canEdit($season), 403);
        $data = $this->validate($data, false);
        $hasTeamIds = array_key_exists('team_ids', $data);
        if ($hasTeamIds) {
            abort_unless(SeasonResource::canManageTeams(), 403);
            $this->assertLeagueTeams($season->league, $data['team_ids']);
        }

        return DB::transaction(function () use ($season, $data, $hasTeamIds): LeagueSeason {
            $season = LeagueSeason::query()->whereKey($season->id)->lockForUpdate()->firstOrFail();
            if ($hasTeamIds) {
                $removed = array_diff($season->teams()->pluck('teams.id')->all(), $data['team_ids']);
                if ($removed !== [] && DB::table('tournament_entries')
                    ->join('tournaments', 'tournaments.id', '=', 'tournament_entries.tournament_id')
                    ->where('tournaments.league_season_id', $season->id)
                    ->whereIn('tournament_entries.team_id', $removed)->exists()) {
                    throw ValidationException::withMessages(['team_ids' => 'Нельзя исключить команду с сохранёнными результатами турнира сезона.']);
                }
                $season->teams()->sync($data['team_ids']);
            }
            $season->update([
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'status' => $data['status'],
            ]);
            return $season->refresh();
        });
    }

    public function archive(LeagueSeason $season): void
    {
        abort_unless(SeasonResource::canArchive(), 403);
        $season->update(['status' => 'archived']);
    }

    public function delete(LeagueSeason $season): void
    {
        abort_unless(SeasonResource::canArchive(), 403);
        DB::transaction(function () use ($season): void {
            $season = LeagueSeason::query()->whereKey($season->id)->lockForUpdate()->firstOrFail();
            if ($season->tournaments()->withTrashed()->exists() || $season->games()->withTrashed()->exists()
                || $season->teams()->exists()) {
                throw ValidationException::withMessages(['season' => 'Сезон со связями нельзя удалить. Используйте архивирование.']);
            }
            $season->delete();
        });
    }

    private function assertLeagueTeams(League $league, array $ids): void
    {
        if ($ids !== [] && $league->teams()->whereKey($ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['team_ids' => 'Сначала добавьте выбранные команды в состав лиги.']);
        }
    }
}
