<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionCandidateTypeEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class StandaloneGameQrJoinController extends Controller
{
    public function __invoke(Request $request, string $event, int $game): Response
    {
        $eventModel = Event::query()
            ->whereRouteIdentifier($event)
            ->with(['venue', 'organizerActor.user.profile'])
            ->firstOrFail();
        $gameModel = Game::query()
            ->whereKey($game)
            ->whereBelongsTo($eventModel)
            ->firstOrFail();

        abort_if(
            $eventModel->type !== EventTypeEnum::GAME
            || (int) $eventModel->primary_game_id !== $gameModel->id
            || $gameModel->recruitment_mode !== GameRecruitmentModeEnum::INDIVIDUAL_DRAFT,
            404,
        );
        abort_unless($eventModel->visibility === EventVisibilityEnum::PUBLIC, 404);

        $identityIds = $request->user()?->canonical()->identityIds() ?? [];
        $latestAdmission = $identityIds === []
            ? null
            : $gameModel->admissions()
                ->where('candidate_type', GameAdmissionCandidateTypeEnum::USER->value)
                ->whereIn('user_id', $identityIds)
                ->latest('id')
                ->first();

        $available = $eventModel->status === EventStatusEnum::PUBLISHED
            && $eventModel->ends_at->isFuture()
            && $gameModel->acceptsAdmissions();

        return ThemeResolver::page('events.game-qr-join', [
            'event' => $eventModel,
            'game' => $gameModel,
            'latestAdmission' => $latestAdmission,
            'available' => $available,
        ]);
    }
}
