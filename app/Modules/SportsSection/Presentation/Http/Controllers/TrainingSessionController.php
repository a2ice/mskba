<?php

namespace App\Modules\SportsSection\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Application\UseCases\CreateTrainingSessionHandler;
use App\Modules\SportsSection\Application\UseCases\ManageTrainingSessionSnapshotHandler;
use App\Modules\SportsSection\Application\UseCases\PublishTrainingSessionEventHandler;
use App\Modules\SportsSection\Application\UseCases\TransitionTrainingSessionHandler;
use App\Modules\SportsSection\Application\UseCases\UpdateTrainingSessionHandler;
use App\Modules\SportsSection\Domain\Enums\TrainingSessionStatusEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\SportsSection\Domain\Models\TrainingSession;
use App\Modules\VenueBooking\Application\Services\MinorAmountParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TrainingSessionController extends Controller
{
    public function store(Request $request, SportsSection $sportsSection, CurrentActorResolver $actors, CreateTrainingSessionHandler $handler, MinorAmountParser $amounts): RedirectResponse
    {
        $data = $this->sessionData($request, $amounts, $sportsSection->currency);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        return $this->execute(fn () => $handler->handle($sportsSection, $actor, $data), 'Занятие создано со snapshot текущего состава.');
    }

    public function update(Request $request, SportsSection $sportsSection, TrainingSession $session, CurrentActorResolver $actors, UpdateTrainingSessionHandler $handler, MinorAmountParser $amounts): RedirectResponse
    {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        return $this->execute(fn () => $handler->handle($sportsSection, $session, $actor, $this->sessionData($request, $amounts, $sportsSection->currency)), 'Занятие обновлено.');
    }

    public function transition(Request $request, SportsSection $sportsSection, TrainingSession $session, CurrentActorResolver $actors, TransitionTrainingSessionHandler $handler): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(TrainingSessionStatusEnum::class)], 'reason' => ['nullable', 'string', 'max:2000']]);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        return $this->execute(fn () => $handler->handle($sportsSection, $session, $actor, TrainingSessionStatusEnum::from($data['status']), $data['reason'] ?? null), 'Статус занятия обновлён.');
    }

    public function linkEvent(Request $request, SportsSection $sportsSection, TrainingSession $session, CurrentActorResolver $actors, PublishTrainingSessionEventHandler $handler): RedirectResponse
    {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        return $this->execute(fn () => $handler->handle($sportsSection, $session, $actor), 'Занятие опубликовано на MSKBA.');
    }

    public function addParticipant(Request $request, SportsSection $sportsSection, TrainingSession $session, ManageTrainingSessionSnapshotHandler $handler): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        return $this->execute(fn () => $handler->addParticipant($sportsSection, $session, User::findOrFail($data['user_id']), $request->user()), 'Участник добавлен только в это занятие.');
    }

    public function removeParticipant(Request $request, SportsSection $sportsSection, TrainingSession $session, int $participant, ManageTrainingSessionSnapshotHandler $handler): RedirectResponse
    {
        return $this->execute(fn () => $handler->removeParticipant($sportsSection, $session, $participant, $request->user()), 'Участник удалён только из этого занятия.');
    }

    public function addCoach(Request $request, SportsSection $sportsSection, TrainingSession $session, ManageTrainingSessionSnapshotHandler $handler): RedirectResponse
    {
        $data = $request->validate(['membership_id' => ['required', 'integer', 'exists:contract_memberships,id']]);

        return $this->execute(fn () => $handler->addCoach($sportsSection, $session, ContractMembership::findOrFail($data['membership_id']), $request->user()), 'Тренер добавлен в snapshot занятия.');
    }

    public function removeCoach(Request $request, SportsSection $sportsSection, TrainingSession $session, int $coach, ManageTrainingSessionSnapshotHandler $handler): RedirectResponse
    {
        return $this->execute(fn () => $handler->removeCoach($sportsSection, $session, $coach, $request->user()), 'Тренер удалён из snapshot занятия.');
    }

    /** @return array<string, mixed> */
    private function sessionData(Request $request, MinorAmountParser $amounts, string $currency): array
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'venue_id' => ['nullable', 'integer', 'exists:venues,id'],
            'venue_court_id' => ['nullable', 'integer', 'exists:venue_courts,id'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
        ]);
        $data['price_override_minor'] = filled($data['price_override'] ?? null)
            ? $amounts->parse((string) $data['price_override'], $currency)
            : null;
        unset($data['price_override']);

        return $data;
    }

    private function execute(callable $action, string $message): RedirectResponse
    {
        try {
            $action();

            return back()->with('status', $message);
        } catch (SportsSectionException|\InvalidArgumentException $exception) {
            return back()->withErrors(['section' => $exception->getMessage()]);
        }
    }
}
