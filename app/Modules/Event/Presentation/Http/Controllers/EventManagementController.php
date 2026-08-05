<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Handlers\ShowEventHandler;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class EventManagementController extends Controller
{
    public function __invoke(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
    ): Response {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        $item = $events->handle($event, $actor);
        abort_unless($access->canManage($item, $actor), 403);

        $permissions = collect($access->effectivePermissions($item, $actor))
            ->map(fn (EventResponsibilityPermissionEnum $permission): string => $permission->value);
        $currentParticipant = $request->user() === null
            ? null
            : $item->participants->firstWhere('user_id', $request->user()->id);

        return ThemeResolver::page('events.management', [
            'event' => $item,
            'currentParticipant' => $currentParticipant,
            'effectivePermissions' => $permissions,
            'responsibilityPermissionGroups' => [
                'event' => EventResponsibilityPermissionEnum::eventPermissions(),
                'mini_game' => EventResponsibilityPermissionEnum::miniGamePermissions(),
            ],
            'confirmedParticipants' => $item->participants
                ->where('status', EventParticipantStatusEnum::CONFIRMED)
                ->where('confirmation_version', $item->participation_confirmation_version)
                ->values(),
            'tentativeParticipants' => $item->participants
                ->where('status', EventParticipantStatusEnum::TENTATIVE)
                ->where('confirmation_version', $item->participation_confirmation_version)
                ->values(),
            'declinedParticipants' => $item->participants
                ->where('status', EventParticipantStatusEnum::LEFT)
                ->where('confirmation_version', $item->participation_confirmation_version)
                ->values(),
        ]);
    }
}
