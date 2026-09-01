<?php

namespace App\Modules\Team\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Application\Services\TeamNotificationService;
use App\Modules\Team\Application\Services\TeamRosterService;
use App\Modules\Team\Domain\Enums\TeamHiringStatusEnum;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Team\Domain\Models\TeamHiringPosition;
use App\Modules\Team\Domain\Models\TeamJoinRequest;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class TeamJoinRequestController extends Controller
{
    public function index(
        string $team,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
    ): Response {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TeamPermissionEnum::MANAGE_JOIN_REQUESTS), 403);

        $requests = $item->joinRequests()
            ->with(['user.profile.activeAvatar', 'reviewedBy.profile', 'hiringPosition'])
            ->orderByRaw("case status when 'pending' then 0 when 'blocked' then 1 else 2 end")
            ->orderByDesc('updated_at')
            ->get();

        return ThemeResolver::page('teams.join-requests', [
            'team' => $item,
            'joinRequests' => $requests,
            'canEditSettings' => $access->allows($item, $actor, TeamPermissionEnum::EDIT_SETTINGS),
            'canManageMembersAndRoster' => $access->canManageMembersAndRoster($item, $actor),
            'canManageVenues' => $access->allows($item, $actor, TeamPermissionEnum::MANAGE_VENUES),
            'canManageHiring' => $access->allows($item, $actor, TeamPermissionEnum::MANAGE_HIRING),
        ]);
    }

    public function store(string $team, Request $request, TeamNotificationService $teamNotifications): RedirectResponse
    {
        $data = $request->validate(['team_hiring_position_id' => ['nullable', 'integer']]);
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $user = $request->user()->canonical();
        abort_if($user->isBlocked() || $user->trashed(), 403);
        abort_if($item->status !== TeamStatusEnum::ACTIVE, 422, 'Команда сейчас не принимает заявки.');

        $joinRequest = DB::transaction(function () use ($item, $user, $data): TeamJoinRequest {
            $lockedTeam = Team::query()->lockForUpdate()->findOrFail($item->id);
            $hiringPositionId = isset($data['team_hiring_position_id']) ? (int) $data['team_hiring_position_id'] : null;
            $hiringPosition = $hiringPositionId === null
                ? null
                : TeamHiringPosition::query()
                    ->where('team_id', $lockedTeam->id)
                    ->whereKey($hiringPositionId)
                    ->lockForUpdate()
                    ->first();
            abort_if(
                $hiringPositionId !== null && ($hiringPosition === null
                    || $hiringPosition->status !== TeamHiringStatusEnum::ACTIVE
                    || $hiringPosition->remainingSpots() === 0),
                422,
                'Эта вакансия уже закрыта.',
            );
            abort_if($hiringPosition === null && ! $lockedTeam->accepts_join_requests, 422, 'Команда сейчас не принимает общие заявки.');

            $identityIds = $user->identityIds();
            $alreadyMember = $lockedTeam->memberships()
                ->whereIn('user_id', $identityIds)
                ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
                ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
                ->exists();
            abort_if($alreadyMember, 422, 'Вы уже состоите в этой команде.');

            $joinRequest = TeamJoinRequest::query()
                ->where('team_id', $lockedTeam->id)
                ->whereIn('user_id', $identityIds)
                ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$user->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if ($joinRequest === null) {
                $joinRequest = new TeamJoinRequest(['team_id' => $lockedTeam->id, 'user_id' => $user->id]);
            }

            abort_if($joinRequest->exists && $joinRequest->status === TeamJoinRequestStatusEnum::BLOCKED, 422, 'Отправка заявок в эту команду для вас заблокирована.');
            abort_if($joinRequest->exists && $joinRequest->status === TeamJoinRequestStatusEnum::PENDING, 422, 'Ваша заявка уже ожидает решения.');
            abort_if($joinRequest->exists && $joinRequest->status === TeamJoinRequestStatusEnum::ACCEPTED, 422, 'Ваша заявка уже была принята.');

            $joinRequest->fill([
                'team_hiring_position_id' => $hiringPosition?->id,
                'status' => TeamJoinRequestStatusEnum::PENDING,
                'review_reason' => null,
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
            ])->save();

            return $joinRequest;
        });
        $teamNotifications->joinRequestSubmitted($item, $joinRequest);

        return back()->with('status', isset($data['team_hiring_position_id']) ? 'Заявка на вакансию отправлена.' : 'Заявка на вступление отправлена.');
    }

    public function respond(
        string $team,
        int $joinRequest,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
        TeamRosterService $rosters,
        TeamNotificationService $teamNotifications,
    ): RedirectResponse {
        $data = $request->validateWithBag('joinRequest'.$joinRequest, [
            'action' => ['required', Rule::in(['accept', 'reject', 'block', 'unblock'])],
            'review_reason' => [
                'nullable',
                'string',
                'max:2000',
                Rule::requiredIf(fn (): bool => in_array($request->input('action'), ['reject', 'block'], true)),
            ],
        ], [
            'review_reason.required' => 'Укажите причину отклонения или блокировки.',
            'review_reason.max' => 'Причина не должна превышать 2000 символов.',
        ]);
        $item = Team::query()->whereRouteIdentifier($team)->with('sportProfiles.lineupMembers')->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TeamPermissionEnum::MANAGE_JOIN_REQUESTS), 403);
        $reviewedByUserId = $actor->user?->canonical()->id;

        $entry = TeamJoinRequest::query()->where('team_id', $item->id)->whereKey($joinRequest)->firstOrFail();
        $action = $data['action'];
        $reviewReason = filled($data['review_reason'] ?? null) ? trim($data['review_reason']) : null;

        if ($action === 'unblock') {
            DB::transaction(function () use ($item, $entry, $reviewedByUserId): void {
                Team::query()->lockForUpdate()->findOrFail($item->id);
                $lockedEntry = TeamJoinRequest::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
                abort_if($lockedEntry->status !== TeamJoinRequestStatusEnum::BLOCKED, 422, 'Разблокировать можно только заблокированную заявку.');
                $lockedEntry->update([
                    'status' => TeamJoinRequestStatusEnum::REJECTED,
                    'review_reason' => null,
                    'reviewed_by_user_id' => $reviewedByUserId,
                    'reviewed_at' => now(),
                ]);
            });
            $teamNotifications->joinRequestReviewed($item, $entry->fresh(), 'unblock');

            return back()->with('status', 'Пользователь разблокирован и сможет отправить заявку повторно.');
        }

        abort_if($entry->status !== TeamJoinRequestStatusEnum::PENDING, 422, 'Эта заявка уже обработана.');

        if ($action === 'accept') {
            DB::transaction(function () use ($item, $entry, $rosters, $reviewReason, $reviewedByUserId): void {
                Team::query()->lockForUpdate()->findOrFail($item->id);
                $hiringPosition = $entry->team_hiring_position_id === null
                    ? null
                    : TeamHiringPosition::query()
                        ->where('team_id', $item->id)
                        ->whereKey($entry->team_hiring_position_id)
                        ->lockForUpdate()
                        ->firstOrFail();
                $lockedEntry = TeamJoinRequest::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
                abort_if($lockedEntry->status !== TeamJoinRequestStatusEnum::PENDING, 422, 'Эта заявка уже обработана.');
                abort_if(
                    $hiringPosition !== null && ($hiringPosition->status !== TeamHiringStatusEnum::ACTIVE || $hiringPosition->remainingSpots() === 0),
                    422,
                    'Вакансия уже закрыта или заполнена.',
                );

                $targetUser = $lockedEntry->user()->firstOrFail()->canonical();
                $membership = $item->memberships()
                    ->whereIn('user_id', $targetUser->identityIds())
                    ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$targetUser->id])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
                $contract = $membership?->contract;
                if ($contract === null) {
                    $contract = Contract::create([
                        'family' => ContractFamilyEnum::MEMBERSHIP,
                        'name' => "Участие в команде «{$item->name}»",
                        'status' => ContractStatusEnum::ACTIVE,
                        'assigned_by' => $reviewedByUserId,
                        'assigned_at' => now(),
                        'assigner' => UserParticipationRoleAssignerEnum::USER,
                    ]);
                    $membership = $contract->membership()->create([
                        'scope_type' => ContractMembershipScopeTypeEnum::TEAM,
                        'scope_id' => $item->id,
                        'user_id' => $targetUser->id,
                        'access_level' => TeamMembershipAccessLevelEnum::PLAYER,
                        'sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
                        'invitation_status' => TeamInvitationStatusEnum::ACCEPTED,
                    ]);
                } else {
                    $contract->update([
                        'status' => ContractStatusEnum::ACTIVE,
                        'assigned_by' => $reviewedByUserId,
                        'assigned_at' => now(),
                    ]);
                    $membership->update([
                        'access_level' => TeamMembershipAccessLevelEnum::PLAYER,
                        'sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
                        'invitation_status' => TeamInvitationStatusEnum::ACCEPTED,
                        'is_captain' => false,
                    ]);
                }

                $contract->permissions()->delete();
                $lockedEntry->update([
                    'status' => TeamJoinRequestStatusEnum::ACCEPTED,
                    'review_reason' => $reviewReason,
                    'reviewed_by_user_id' => $reviewedByUserId,
                    'reviewed_at' => now(),
                ]);
                $rosters->synchronizePlayer($item, $membership->id);

                if ($hiringPosition !== null) {
                    $spotsFilled = $hiringPosition->spots_filled + 1;
                    $hiringPosition->update([
                        'spots_filled' => $spotsFilled,
                        'status' => $spotsFilled >= $hiringPosition->spots_total
                            ? TeamHiringStatusEnum::CLOSED
                            : TeamHiringStatusEnum::ACTIVE,
                        'closed_at' => $spotsFilled >= $hiringPosition->spots_total ? now() : null,
                    ]);
                }
            });
            $teamNotifications->joinRequestReviewed($item, $entry->fresh(), 'accept');

            return back()->with('status', 'Заявка принята. Пользователь добавлен в команду.');
        }

        DB::transaction(function () use ($item, $entry, $action, $reviewReason, $reviewedByUserId): void {
            Team::query()->lockForUpdate()->findOrFail($item->id);
            $lockedEntry = TeamJoinRequest::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            abort_if($lockedEntry->status !== TeamJoinRequestStatusEnum::PENDING, 422, 'Эта заявка уже обработана.');
            $lockedEntry->update([
                'status' => $action === 'block' ? TeamJoinRequestStatusEnum::BLOCKED : TeamJoinRequestStatusEnum::REJECTED,
                'review_reason' => $reviewReason,
                'reviewed_by_user_id' => $reviewedByUserId,
                'reviewed_at' => now(),
            ]);
        });
        $teamNotifications->joinRequestReviewed($item, $entry->fresh(), $action);

        return back()->with('status', $action === 'block' ? 'Пользователь заблокирован.' : 'Заявка отклонена.');
    }
}
