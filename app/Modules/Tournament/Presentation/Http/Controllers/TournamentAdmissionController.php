<?php

namespace App\Modules\Tournament\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Application\Services\TournamentAdmissionService;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Tournament\Domain\Models\TournamentAdmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class TournamentAdmissionController extends Controller
{
    public function invite(Request $request, string $tournament, TournamentAdmissionService $service, CurrentActorResolver $actors): RedirectResponse
    {
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();
        $data = $request->validate($item->recruitment_mode === TournamentRecruitmentModeEnum::PREFORMED_TEAMS
            ? ['team_id' => ['required', 'integer', 'exists:teams,id']]
            : ['user_id' => ['required', 'integer', 'exists:users,id']]);
        $candidate = $item->recruitment_mode === TournamentRecruitmentModeEnum::PREFORMED_TEAMS
            ? Team::query()->findOrFail($data['team_id'])
            : User::query()->whereKey($data['user_id'])->where('status', UserStatusEnum::CONFIRMED->value)->firstOrFail();

        return $this->run(fn () => $service->invite($item, $actors->resolveForRequest($request) ?? abort(403), $candidate), 'Приглашение отправлено.');
    }

    public function apply(Request $request, string $tournament, TournamentAdmissionService $service, CurrentActorResolver $actors): RedirectResponse
    {
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();
        $actor = $actors->resolveForRequest($request) ?? abort(403);
        $candidate = $item->recruitment_mode === TournamentRecruitmentModeEnum::PREFORMED_TEAMS
            ? Team::query()->findOrFail($request->validate(['team_id' => ['required', 'integer', 'exists:teams,id']])['team_id'])
            : $request->user();

        return $this->run(fn () => $service->apply($item, $actor, $candidate), 'Заявка отправлена.');
    }

    public function respond(Request $request, string $tournament, TournamentAdmission $admission, TournamentAdmissionService $service, CurrentActorResolver $actors): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in([
            TournamentAdmissionStatusEnum::ACCEPTED->value,
            TournamentAdmissionStatusEnum::DECLINED->value,
        ])]]);
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();

        return $this->run(fn () => $service->respond($item, $admission, $actors->resolveForRequest($request) ?? abort(403), TournamentAdmissionStatusEnum::from($data['decision'])), 'Ответ сохранён.');
    }

    public function revoke(Request $request, string $tournament, TournamentAdmission $admission, TournamentAdmissionService $service, CurrentActorResolver $actors): RedirectResponse
    {
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();

        return $this->run(fn () => $service->revoke($item, $admission, $actors->resolveForRequest($request) ?? abort(403)), 'Допуск отозван.');
    }

    private function run(callable $operation, string $message): RedirectResponse
    {
        try {
            $operation();
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', $message);
    }
}
