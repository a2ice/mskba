<?php

namespace App\Modules\Tournament\Application\Services;

use App\Modules\Identity\Application\DTO\PrivacyConsentDTO;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\UseCases\CreateUserAccountHandler;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserConsent;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionCandidateTypeEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionDirectionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionRoleEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionSourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPhaseEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Tournament\Domain\Models\TournamentAdmission;
use App\Modules\Tournament\Domain\Models\TournamentEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TournamentOnSiteRegistrationService
{
    public function __construct(
        private readonly CreateUserAccountHandler $accounts,
        private readonly CurrentActorResolver $actors,
        private readonly TournamentAccess $access,
        private readonly CreateUserNotificationHandler $notifications,
    ) {}

    /** @param Collection<int, TournamentAdmissionRoleEnum> $roles */
    public function registerAndApply(Tournament $tournament, string $username, Collection $roles, PrivacyConsentDTO $consent): array
    {
        return DB::transaction(function () use ($tournament, $username, $roles, $consent): array {
            $lockedTournament = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->assertAvailable($lockedTournament);
            if ($roles->isEmpty()) {
                throw new InvalidArgumentException('Выберите хотя бы одну роль для участия в турнире.');
            }
            $user = $this->accounts->handle(
                username: $username,
                password: null,
                registrationChannel: UserRegistrationChannelEnum::TOURNAMENT_ON_SITE,
            );
            $this->assignProjectRoles($user, $roles);
            $user->consents()->create([
                'type' => UserConsent::TYPE_PRIVACY_POLICY,
                'document_version' => $consent->documentVersion,
                'accepted_at' => $consent->acceptedAt,
                'source' => $consent->source,
                'ip_address' => $consent->ipAddress,
                'user_agent' => $consent->userAgent,
            ]);

            $actor = $this->actors->resolve($user, null) ?? throw new InvalidArgumentException('Не удалось создать участника.');
            $admission = $this->createAdmission($lockedTournament, $user, $actor, $roles);

            DB::afterCommit(fn () => $this->notifyOrganizer($lockedTournament, $admission));

            return ['user' => $user, 'admission' => $admission];
        });
    }

    /** @param Collection<int, TournamentAdmissionRoleEnum> $roles */
    public function apply(Tournament $tournament, User $user, Actor $actor, Collection $roles): TournamentAdmission
    {
        return DB::transaction(function () use ($tournament, $user, $actor, $roles): TournamentAdmission {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->assertAvailable($locked);
            if ($roles->isEmpty()) {
                throw new InvalidArgumentException('Выберите хотя бы одну роль для участия в турнире.');
            }
            if ($locked->admissions()->where('user_id', $user->id)->whereIn('status', ['pending', 'accepted'])->exists()) {
                throw new InvalidArgumentException('У вас уже есть активная заявка на этот турнир.');
            }
            $admission = $this->createAdmission($locked, $user, $actor, $roles);
            DB::afterCommit(fn () => $this->notifyOrganizer($locked, $admission));

            return $admission;
        });
    }

    public function accept(Tournament $tournament, TournamentAdmission $admission, Actor $actor, ?TournamentEntry $entry): void
    {
        DB::transaction(function () use ($tournament, $admission, $actor, $entry): void {
            $lockedTournament = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($lockedTournament, $actor, TournamentPermissionEnum::MANAGE_GAMES);
            $lockedAdmission = TournamentAdmission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();
            if ($lockedAdmission->tournament_id !== $lockedTournament->id
                || $lockedAdmission->source !== TournamentAdmissionSourceEnum::ON_SITE
                || $lockedAdmission->status !== TournamentAdmissionStatusEnum::PENDING) {
                throw new InvalidArgumentException('Заявка недоступна для подтверждения.');
            }
            $isPlayer = $lockedAdmission->roles?->contains(TournamentAdmissionRoleEnum::PLAYER) === true;
            $needsTeam = $isPlayer && ($lockedTournament->participant_pool_locked_at !== null || $lockedTournament->matches()->whereNotNull('game_id')->exists());
            $lockedEntry = $entry === null ? null : $lockedTournament->entries()->whereKey($entry->id)->lockForUpdate()->first();
            if ($needsTeam && $lockedEntry === null) {
                throw new InvalidArgumentException('Выберите команду для нового участника.');
            }
            if ($lockedEntry !== null && $lockedEntry->members()->where('user_id', $lockedAdmission->user_id)->exists()) {
                throw new InvalidArgumentException('Участник уже добавлен в эту команду.');
            }
            $lockedAdmission->forceFill(['status' => TournamentAdmissionStatusEnum::ACCEPTED, 'responded_by_actor_id' => $actor->id, 'responded_at' => now()])->save();
            if ($lockedEntry !== null && $isPlayer) {
                $position = ((int) $lockedEntry->members()->max('position')) + 1;
                $lockedEntry->members()->create(['user_id' => $lockedAdmission->user_id, 'position' => $position]);
                $this->addToFutureGames($lockedTournament, $lockedEntry, (int) $lockedAdmission->user_id);
            }
        });
    }

    private function assertAvailable(Tournament $tournament): void
    {
        if (! $tournament->allows_on_site_registration || $tournament->status !== TournamentStatusEnum::CONFIRMED) {
            throw new InvalidArgumentException('Регистрация на месте для этого турнира закрыта.');
        }
        if ($tournament->phase() === TournamentPhaseEnum::COMPLETED) {
            throw new InvalidArgumentException('Турнир уже завершён.');
        }
        if ($tournament->recruitment_mode !== TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT) {
            throw new InvalidArgumentException('Регистрация на месте доступна только для турнира с отдельными игроками.');
        }
        if (($tournament->format?->sideSize() ?? 1) === 1) {
            throw new InvalidArgumentException('Регистрация на месте пока доступна для balanced-турниров 3×3 и 5×5.');
        }
    }

    private function assignProjectRoles(User $user, Collection $roles): void
    {
        $roles->map(fn (TournamentAdmissionRoleEnum $role) => match ($role) {
            TournamentAdmissionRoleEnum::PLAYER => UserParticipationRoleEnum::PLAYER,
            TournamentAdmissionRoleEnum::COACH => UserParticipationRoleEnum::COACH,
            TournamentAdmissionRoleEnum::MANAGER => null,
        })->filter()->unique()->each(function (UserParticipationRoleEnum $role) use ($user): void {
            $user->participationRoles(false)->updateOrCreate(['role' => $role], [
                'status' => UserParticipationRoleStatusEnum::ACTIVE,
                'assigned_at' => now(),
                'assigned_by' => $user->id,
                'assigner' => UserParticipationRoleAssignerEnum::USER,
                'expires_at' => null,
                'comment' => 'Выбрана при регистрации на месте на турнир.',
            ]);
        });
    }

    private function createAdmission(Tournament $tournament, User $user, Actor $actor, Collection $roles): TournamentAdmission
    {
        $this->assignProjectRoles($user, $roles);

        return $tournament->admissions()->create([
            'candidate_type' => TournamentAdmissionCandidateTypeEnum::USER,
            'user_id' => $user->id,
            'direction' => TournamentAdmissionDirectionEnum::APPLICATION,
            'source' => TournamentAdmissionSourceEnum::ON_SITE,
            'roles' => $roles,
            'status' => TournamentAdmissionStatusEnum::PENDING,
            'requested_by_actor_id' => $actor->id,
            'comment' => 'Заявка через страницу регистрации на месте.',
        ]);
    }

    private function addToFutureGames(Tournament $tournament, TournamentEntry $entry, int $userId): void
    {
        $tournament->matches()->whereNotNull('game_id')->with(['game.sides', 'game.rosterEntries'])->get()
            ->filter(fn ($match) => $match->entry_a_id === $entry->id || $match->entry_b_id === $entry->id)
            ->filter(fn ($match) => $match->game?->actual_started_at === null)
            ->each(function ($match) use ($entry, $userId): void {
                $slot = $match->entry_a_id === $entry->id ? 'A' : 'B';
                $side = $match->game->sides->firstWhere('slot', $slot);
                if ($side !== null) {
                    $match->game->rosterEntries()->firstOrCreate(['user_id' => $userId], ['game_side_id' => $side->id, 'status' => 'selected']);
                }
            });
    }

    private function notifyOrganizer(Tournament $tournament, TournamentAdmission $admission): void
    {
        $userId = $tournament->createdByActor()->value('user_id');
        if ($userId === null) {
            return;
        }
        $this->notifications->handle(new CreateUserNotificationDTO(
            userId: (int) $userId,
            type: UserNotificationTypeEnum::REMINDER,
            title: 'Регистрация на месте',
            body: 'Поступила заявка участника на турнир «'.$tournament->title.'».',
            actionUrl: route('tournaments.manage', $tournament->routeIdentifier(), false).'#participants',
            actionText: 'Рассмотреть заявку',
            payload: ['source' => 'tournament.on_site.application', 'tournament_id' => $tournament->id, 'tournament_admission_id' => $admission->id],
        ));
    }
}
