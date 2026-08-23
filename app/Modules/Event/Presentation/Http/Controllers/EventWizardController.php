<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Telegram\Application\Services\TelegramChatRegistry;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

final class EventWizardController extends Controller
{
    public function show(Request $request, TelegramChatRegistry $telegramChats): Response
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::enum(EventTypeEnum::class)],
        ]);
        $selectedType = isset($validated['type']) ? EventTypeEnum::from($validated['type']) : EventTypeEnum::GAME;
        $now = CarbonImmutable::now((string) config('app.timezone', 'Europe/Moscow'));
        $defaultStartsAt = $now->ceilMinute();

        return ThemeResolver::page('events.wizard', [
            'types' => EventTypeEnum::cases(),
            'visibilities' => EventVisibilityEnum::cases(),
            'selectedType' => $selectedType,
            'defaultStartsAt' => $defaultStartsAt->format('Y-m-d\TH:i'),
            'minimumStartsAt' => $now->subMinute()->startOfMinute()->format('Y-m-d\TH:i'),
            'defaultTitle' => $selectedType->label().' - '.$now->format('Ymd'),
            'durationOptions' => range(30, 480, 30),
            'telegramChats' => $telegramChats->activeEventChats(),
        ]);
    }

    public function teams(
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $teamAccess,
    ): JsonResponse {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:40'],
            'ids' => ['nullable', 'array', 'max:2'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 401);

        $query = trim((string) ($validated['q'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 32);
        $requestedIds = collect($validated['ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->values();
        $managedIds = $this->manageableTeamIds($actor);

        $publicTeams = Team::query()
            ->with('logo')
            ->competitionInvitable()
            ->when($query !== '', fn ($builder) => $builder->whereRaw(
                'LOWER(name) LIKE ?',
                ['%'.mb_strtolower($query).'%'],
            ))
            ->orderBy('name')
            ->limit($limit)
            ->get();

        $managedTeams = $managedIds->isEmpty()
            ? collect()
            : Team::query()
                ->with('logo')
                ->whereIn('id', $managedIds)
                ->whereNull('temporary_for_event_id')
                ->where('status', TeamStatusEnum::ACTIVE->value)
                ->when($query !== '', fn ($builder) => $builder->whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%'.mb_strtolower($query).'%'],
                ))
                ->orderBy('name')
                ->get();

        $selectedTeams = $requestedIds->isEmpty()
            ? collect()
            : Team::query()
                ->with('logo')
                ->whereIn('id', $requestedIds)
                ->whereNull('temporary_for_event_id')
                ->where('status', TeamStatusEnum::ACTIVE->value)
                ->where(function ($builder) use ($managedIds): void {
                    $builder->where('accepts_competition_invitations', true);
                    if ($managedIds->isNotEmpty()) {
                        $builder->orWhereIn('id', $managedIds);
                    }
                })
                ->get();

        $teams = $selectedTeams
            ->concat($managedTeams)
            ->concat($publicTeams)
            ->unique('id')
            ->map(function (Team $team) use ($actor, $teamAccess): array {
                $manageable = $teamAccess->allows(
                    $team,
                    $actor,
                    TeamPermissionEnum::MANAGE_GAME_PARTICIPATION,
                );

                return [
                    'id' => $team->id,
                    'name' => $team->name,
                    'logo_url' => $team->logo?->publicUrl() ?? asset('images/team-placeholder.webp'),
                    'manageable' => $manageable,
                    'accepts_invitations' => $team->acceptsCompetitionInvitations(),
                    'selection_hint' => $manageable
                        ? 'Ваша команда — согласие не требуется'
                        : 'После создания будет отправлено приглашение',
                ];
            })
            ->sortBy([
                ['manageable', 'desc'],
                ['name', 'asc'],
            ])
            ->take($limit)
            ->values();

        return response()->json(['teams' => $teams]);
    }

    /** @return Collection<int, int> */
    private function manageableTeamIds($actor): Collection
    {
        $user = $actor->user?->canonical();
        if ($user === null || $user->isBlocked() || $user->trashed()) {
            return collect();
        }

        $identityIds = $user->identityIds();
        $createdIds = Team::query()
            ->whereHas('createdByActor', fn ($builder) => $builder->whereIn('user_id', $identityIds))
            ->pluck('id');

        $delegatedIds = Team::query()
            ->whereHas('memberships', fn ($builder) => $builder
                ->whereIn('user_id', $identityIds)
                ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
                ->whereHas('contract', fn ($contract) => $contract
                    ->where('status', ContractStatusEnum::ACTIVE->value)
                    ->whereHas('permissions', fn ($permissions) => $permissions
                        ->where('permission', TeamPermissionEnum::MANAGE_GAME_PARTICIPATION->value))))
            ->pluck('id');

        return $createdIds->concat($delegatedIds)->map(fn ($id): int => (int) $id)->unique()->values();
    }
}
