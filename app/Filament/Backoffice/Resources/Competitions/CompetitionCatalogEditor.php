<?php

namespace App\Filament\Backoffice\Resources\Competitions;

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Tournament\Domain\Enums\TournamentEnrollmentPolicyEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\League;
use App\Modules\Tournament\Domain\Models\LeagueSeason;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Backoffice writes go through a single authorization and domain-invariant gate.
 * Existing competition records, admissions, results and player statistics stay intact.
 */
final class CompetitionCatalogEditor
{
    private function actorId(Permission $permission): int
    {
        $user = auth('web')->user()?->canonical();
        if ($user === null
            || ! app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_ACCESS)
            || ! app(BackofficePermissionManager::class)->allows($user, $permission)) {
            throw new AuthorizationException('Недостаточно прав для управления соревнованиями.');
        }

        return app(CurrentActorResolver::class)->resolve($user, null)?->id
            ?? throw new AuthorizationException('Невозможно определить администратора.');
    }

    private function validateLeague(array $data, ?League $record = null): array
    {
        $data['slug'] = Str::slug((string) (($data['slug'] ?? null) ?: ($data['name'] ?? $record?->name ?? '')));
        return Validator::make($data, [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'slug' => ['required', 'string', 'max:180', Rule::unique('leagues', 'slug')->ignore($record?->id)],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::in(['active', 'archived'])],
        ])->validate();
    }

    public function createLeague(array $data): League
    {
        $actorId = $this->actorId(Permission::BACKOFFICE_LEAGUE_MANAGE);
        $validated = $this->validateLeague(['status' => 'active', ...$data]);
        return League::query()->create([...$validated, 'created_by_actor_id' => $actorId]);
    }

    public function updateLeague(League $league, array $data): League
    {
        $this->actorId(Permission::BACKOFFICE_LEAGUE_MANAGE);
        abort_if($league->trashed(), 404);
        $league->update($this->validateLeague([...$league->only(['name', 'slug', 'description', 'status']), ...$data], $league));
        return $league->refresh();
    }

    public function deleteLeague(League $league): void
    {
        $this->actorId(Permission::BACKOFFICE_LEAGUE_MANAGE);
        if ($league->seasons()->withTrashed()->exists() || $league->tournaments()->withTrashed()->exists()) {
            throw ValidationException::withMessages(['league' => 'Лига связана с сезонами или турнирами. Вместо удаления переведите её в архив.']);
        }
        $league->delete();
    }

    public function restoreLeague(League $league): void
    {
        $this->actorId(Permission::BACKOFFICE_LEAGUE_MANAGE);
        $league->restore();
    }

    private function validateSeason(array $data, ?LeagueSeason $record = null): array
    {
        $data['slug'] = Str::slug((string) (($data['slug'] ?? null) ?: ($data['name'] ?? $record?->name ?? '')));
        $data['starts_on'] = ($data['starts_on'] ?? null) ?: null;
        $data['ends_on'] = ($data['ends_on'] ?? null) ?: null;

        $validated = Validator::make($data, [
            'league_id' => ['required', 'integer', Rule::exists('leagues', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'slug' => ['required', 'string', 'max:180',
                Rule::unique('league_seasons', 'slug')->where('league_id', $data['league_id'] ?? 0)->ignore($record?->id)],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => array_filter(['nullable', 'date', $data['starts_on'] ? 'after_or_equal:starts_on' : null]),
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::in(['planned', 'active', 'completed', 'archived'])],
        ])->validate();

        $league = League::query()->findOrFail($validated['league_id']);
        if ($league->status !== 'active' && ($record === null || (int) $record->league_id !== (int) $league->id)) {
            throw ValidationException::withMessages(['league_id' => 'Нельзя создавать или переносить сезон в архивную лигу.']);
        }
        if ($record !== null && (int) $record->league_id !== (int) $validated['league_id']
            && $record->tournaments()->withTrashed()->exists()) {
            throw ValidationException::withMessages(['league_id' => 'Нельзя менять лигу сезона, к которому привязаны турниры.']);
        }
        return $validated;
    }

    public function createSeason(array $data): LeagueSeason
    {
        $actorId = $this->actorId(Permission::BACKOFFICE_SEASON_MANAGE);
        $validated = $this->validateSeason(['status' => 'planned', ...$data]);
        return LeagueSeason::query()->create([...$validated, 'created_by_actor_id' => $actorId]);
    }

    public function updateSeason(LeagueSeason $season, array $data): LeagueSeason
    {
        $this->actorId(Permission::BACKOFFICE_SEASON_MANAGE);
        abort_if($season->trashed(), 404);
        $season->update($this->validateSeason([
            ...$season->only(['league_id', 'name', 'slug', 'starts_on', 'ends_on', 'description', 'status']),
            ...$data,
        ], $season));
        return $season->refresh();
    }

    public function deleteSeason(LeagueSeason $season): void
    {
        $this->actorId(Permission::BACKOFFICE_SEASON_MANAGE);
        if ($season->tournaments()->withTrashed()->exists()) {
            throw ValidationException::withMessages(['season' => 'У сезона есть турниры. Для сохранения истории переведите его в архив.']);
        }
        $season->delete();
    }

    public function restoreSeason(LeagueSeason $season): void
    {
        $this->actorId(Permission::BACKOFFICE_SEASON_MANAGE);
        if ($season->league()->whereNull('deleted_at')->doesntExist()) {
            throw ValidationException::withMessages(['season' => 'Сначала восстановите лигу.']);
        }
        $season->restore();
    }

    private function validateTournament(array $data, ?Tournament $record = null): array
    {
        $data['league_id'] = ($data['league_id'] ?? null) ?: null;
        $data['league_season_id'] = ($data['league_season_id'] ?? null) ?: null;
        $data['ends_on'] = ($data['ends_on'] ?? null) ?: null;
        $data['format'] = ($data['format'] ?? null) ?: null;

        $validated = Validator::make($data, [
            'title' => ['required', 'string', 'min:2', 'max:150'],
            'status' => ['required', Rule::in(array_column(TournamentStatusEnum::cases(), 'value'))],
            'status_comment' => ['nullable', 'string', 'max:5000'],
            'starts_on' => ['required', 'date'],
            'ends_on' => array_filter(['nullable', 'date', $data['starts_on'] ? 'after_or_equal:starts_on' : null]),
            'short_description' => ['nullable', 'string', 'max:10000'],
            'full_description' => ['nullable', 'string', 'max:50000'],
            'format' => ['nullable', Rule::in([
                GameFormatEnum::BASKETBALL_5X5->value,
                GameFormatEnum::STREETBALL_3X3->value,
                GameFormatEnum::STREETBALL_1X1->value,
            ])],
            'league_id' => ['nullable', 'integer', Rule::exists('leagues', 'id')->whereNull('deleted_at')],
            'league_season_id' => ['nullable', 'integer', Rule::exists('league_seasons', 'id')->whereNull('deleted_at')],
            'recruitment_mode' => ['required', Rule::in(array_column(TournamentRecruitmentModeEnum::cases(), 'value'))],
            'enrollment_policy' => ['required', Rule::in(array_column(TournamentEnrollmentPolicyEnum::cases(), 'value'))],
            'round_robin_legs' => ['required', 'integer', 'between:1,4'],
        ])->validate();

        if ($validated['league_season_id'] !== null) {
            $season = LeagueSeason::query()->findOrFail($validated['league_season_id']);
            if ($validated['league_id'] === null || (int) $season->league_id !== (int) $validated['league_id']) {
                throw ValidationException::withMessages(['league_season_id' => 'Сезон должен принадлежать выбранной лиге.']);
            }
        }

        if ($record !== null && $record->competitionHasStarted()) {
            foreach (['league_id', 'league_season_id', 'format', 'starts_on', 'ends_on', 'recruitment_mode', 'enrollment_policy', 'round_robin_legs'] as $field) {
                $old = $record->$field;
                $old = $old instanceof \BackedEnum ? $old->value : ($old instanceof \DateTimeInterface ? $old->format('Y-m-d') : $old);
                if ((string) ($old ?? '') !== (string) ($validated[$field] ?? '')) {
                    throw ValidationException::withMessages([$field => 'После начала игр эту настройку менять нельзя.']);
                }
            }
            if ($validated['status'] === TournamentStatusEnum::CANCELLED->value) {
                throw ValidationException::withMessages(['status' => 'Начавшийся турнир нельзя отменить через редактирование карточки.']);
            }
        }
        return $validated;
    }

    public function createTournament(array $data): Tournament
    {
        $actorId = $this->actorId(Permission::BACKOFFICE_TOURNAMENT_MANAGE);
        $validated = $this->validateTournament([
            'status' => TournamentStatusEnum::UNCONFIRMED->value,
            'recruitment_mode' => TournamentRecruitmentModeEnum::PREFORMED_TEAMS->value,
            'enrollment_policy' => TournamentEnrollmentPolicyEnum::FIXED_POOL->value,
            'round_robin_legs' => 1,
            ...$data,
        ]);

        return Tournament::query()->create([
            ...$validated,
            'alias' => Str::slug($validated['title']).'-'.Str::lower(Str::random(7)),
            'created_by_actor_id' => $actorId,
        ]);
    }

    public function updateTournament(Tournament $tournament, array $data): Tournament
    {
        $this->actorId(Permission::BACKOFFICE_TOURNAMENT_MANAGE);
        abort_if($tournament->trashed(), 404);
        $values = $tournament->only([
            'title', 'status', 'status_comment', 'starts_on', 'ends_on',
            'short_description', 'full_description', 'format', 'league_id', 'league_season_id',
            'recruitment_mode', 'enrollment_policy', 'round_robin_legs',
        ]);
        foreach (['status', 'format', 'recruitment_mode', 'enrollment_policy'] as $key) {
            if ($values[$key] instanceof \BackedEnum) $values[$key] = $values[$key]->value;
        }
        foreach (['starts_on', 'ends_on'] as $key) {
            if ($values[$key] instanceof \DateTimeInterface) $values[$key] = $values[$key]->format('Y-m-d');
        }
        $tournament->update($this->validateTournament([...$values, ...$data], $tournament));
        return $tournament->refresh();
    }

    public function deleteTournament(Tournament $tournament): void
    {
        $this->actorId(Permission::BACKOFFICE_TOURNAMENT_MANAGE);
        if ($tournament->matches()->withTrashed()->exists()
            || $tournament->entries()->exists()
            || $tournament->admissions()->exists()) {
            throw ValidationException::withMessages(['tournament' => 'Турнир уже связан с участниками или играми. Историю сохраняем — отмените турнир вместо удаления.']);
        }
        $tournament->delete();
    }

    public function restoreTournament(Tournament $tournament): void
    {
        $this->actorId(Permission::BACKOFFICE_TOURNAMENT_MANAGE);
        $tournament->restore();
    }
}
