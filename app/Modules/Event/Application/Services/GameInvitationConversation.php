<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\GameAdmission;
use App\Modules\Event\Domain\Models\GameInvitationThread;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\RequestThread;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use Illuminate\Support\Facades\DB;

final class GameInvitationConversation
{
    public function __construct(
        private readonly EventManagementAccess $events,
        private readonly TeamManagementAccess $teams,
    ) {}

    /** Called under the parent event and game locks in GameAdmissionService. */
    public function recordInvitation(Game $game, GameAdmission $admission): GameInvitationThread
    {
        $context = GameInvitationThread::query()->firstOrCreate([
            'game_id' => $game->id,
            'team_id' => $admission->team_id,
        ]);
        RequestThread::query()->firstOrCreate([
            'type' => 'game_invitation',
            'subject_id' => $context->id,
        ]);
        $context->touch();

        return $context;
    }

    public function canRepresentTeam(GameInvitationThread $context, User $user): bool
    {
        $user = $user->canonical();
        $team = $context->team;
        if ($team === null || $user->isBlocked() || $user->trashed()) {
            return false;
        }

        return $this->teams->allows($team, $this->actor($user), TeamPermissionEnum::MANAGE_GAME_PARTICIPATION);
    }

    public function canOrganize(GameInvitationThread $context, User $user): bool
    {
        $user = $user->canonical();
        $event = $context->game?->event;
        if ($event === null || $user->isBlocked() || $user->trashed()) {
            return false;
        }

        return $this->events->allows($event, $this->actor($user), EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS);
    }

    public function canRead(GameInvitationThread $context, User $user): bool
    {
        return $this->canOrganize($context, $user) || $this->canRepresentTeam($context, $user);
    }

    public function invitations(GameInvitationThread $context)
    {
        return GameAdmission::query()->where('game_id', $context->game_id)
            ->where('team_id', $context->team_id)
            ->where('direction', GameAdmissionDirectionEnum::INVITATION->value);
    }

    /** Invitation status never closes the associated discussion. */
    public function send(GameInvitationThread $context, User $user, string $body): void
    {
        $user = $user->canonical();
        $body = trim($body);
        abort_if($body === '' || mb_strlen($body) > 2000, 422);
        DB::transaction(function () use ($context, $user, $body): void {
            $locked = GameInvitationThread::query()->lockForUpdate()->findOrFail($context->id);
            abort_unless($this->canRead($locked, $user), 403);
            $thread = $locked->thread()->firstOrFail();
            $thread->messages()->create(['sender_user_id' => $user->id, 'body' => $body]);
            $locked->touch();
        });
    }

    private function actor(User $user): Actor
    {
        return (new Actor(['user_id' => $user->id]))->setRelation('user', $user);
    }
}
