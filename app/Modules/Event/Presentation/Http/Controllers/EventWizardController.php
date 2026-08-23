<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Telegram\Application\Services\TelegramChatRegistry;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        ]);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 401);

        $query = trim((string) ($validated['q'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 32);
        $applySearch = static function ($builder) use ($query): void {
            if ($query !== '') {
                $builder->where('name', 'like', '%'.$query.'%');
            }
        };

        $publicTeams = Team::query()
            ->with('logo')
            ->competitionInvitable()
            ->when($query !== '', $applySearch)
            ->orderBy('name')
            ->limit($limit)
            ->get();

        $managedTeams = Team::query()
            ->with('logo')
            ->whereNull('temporary_for_event_id')
            ->where('status', TeamStatusEnum::ACTIVE->value)
            ->when($query !== '', $applySearch)
            ->orderBy('name')
            ->limit(120)
            ->get()
            ->filter(fn (Team $team): bool => $teamAccess->allows(
                $team,
                $actor,
                TeamPermissionEnum::MANAGE_GAME_PARTICIPATION,
            ));

        $teams = $managedTeams
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
}
