<?php

namespace App\Filament\Backoffice\Resources\Competitions;

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\League\Domain\Models\League;
use App\Modules\League\Domain\Models\LeagueSeason;
use App\Modules\Tournament\Domain\Enums\TournamentEnrollmentPolicyEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Support\Text\CyrillicTransliterator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Backoffice integration over the existing tournament lifecycle and database. */
final class CompetitionCatalogEditor
{
    private function actorId(Permission $permission): int
    {
        $user = auth('web')->user()?->canonical();
        if ($user === null
            || ! app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_ACCESS)
            || ! app(BackofficePermissionManager::class)->allows($user, $permission)) {
            throw new AuthorizationException('Недостаточно прав для управления турнирами.');
        }

        return app(CurrentActorResolver::class)->resolve($user, null)?->id
            ?? throw new AuthorizationException('Невозможно определить администратора.');
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
            'round_robin_legs' => ['required', 'integer', 'between:1,2'],
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
        $actorId = $this->actorId(Permission::BACKOFFICE_TOURNAMENT_CREATE);
        $validated = $this->validateTournament([
            'status' => TournamentStatusEnum::UNCONFIRMED->value,
            'recruitment_mode' => TournamentRecruitmentModeEnum::PREFORMED_TEAMS->value,
            'enrollment_policy' => TournamentEnrollmentPolicyEnum::FIXED_POOL->value,
            'round_robin_legs' => 1,
            ...$data,
        ]);

        return Tournament::query()->create([
            ...$validated,
            'alias' => (Str::slug(app(CyrillicTransliterator::class)->transliterate($validated['title'])) ?: 'tournament').'-'.Str::lower(Str::random(7)),
            'created_by_actor_id' => $actorId,
        ]);
    }

    public function updateTournament(Tournament $tournament, array $data): Tournament
    {
        $this->actorId(Permission::BACKOFFICE_TOURNAMENT_EDIT);
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
        $this->actorId(Permission::BACKOFFICE_TOURNAMENT_DELETE);
        if ($tournament->matches()->withTrashed()->exists()
            || $tournament->entries()->exists()
            || $tournament->admissions()->exists()) {
            throw ValidationException::withMessages(['tournament' => 'Турнир уже связан с участниками или играми. Историю сохраняем — отмените турнир вместо удаления.']);
        }
        $tournament->delete();
    }

    public function restoreTournament(Tournament $tournament): void
    {
        $this->actorId(Permission::BACKOFFICE_TOURNAMENT_DELETE);
        $tournament->restore();
    }
}
