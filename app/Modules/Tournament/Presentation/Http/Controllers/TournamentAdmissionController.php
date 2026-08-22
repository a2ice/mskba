<?php

namespace App\Modules\Tournament\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Application\Services\TournamentAccess;
use App\Modules\Tournament\Application\Services\TournamentAdmissionService;
use App\Modules\Tournament\Application\Services\TournamentOnSiteRegistrationService;
use App\Modules\Tournament\Application\Services\TournamentParticipantPoolService;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionRoleEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionSourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPhaseEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Tournament\Domain\Models\TournamentAdmission;
use App\Modules\Tournament\Domain\Models\TournamentEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            : User::query()->whereKey($data['user_id'])->firstOrFail();
        $actor = $actors->resolveForRequest($request) ?? abort(403);

        return $this->run(function () use ($service, $item, $actor, $candidate): void {
            if ($candidate instanceof Team && ! $candidate->acceptsCompetitionInvitations()) {
                throw new InvalidArgumentException('Команда запретила приглашения в игры и турниры.');
            }
            $service->invite($item, $actor, $candidate);
        }, 'Приглашение отправлено.');
    }

    public function apply(Request $request, string $tournament, TournamentAdmissionService $service, CurrentActorResolver $actors): RedirectResponse
    {
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();
        $actor = $actors->resolveForRequest($request) ?? abort(403);
        if ($item->recruitment_mode === TournamentRecruitmentModeEnum::PREFORMED_TEAMS) {
            $candidate = Team::query()->findOrFail($request->validate(['team_id' => ['required', 'integer', 'exists:teams,id']])['team_id']);
            $roles = null;
        } else {
            $candidate = $request->user();
            $data = $request->validate([
                'roles' => ['required', 'array', 'min:1'],
                'roles.*' => ['required', 'distinct', Rule::enum(TournamentAdmissionRoleEnum::class)],
            ]);
            $roles = collect($data['roles'])
                ->map(static fn (string $role): TournamentAdmissionRoleEnum => TournamentAdmissionRoleEnum::from($role))
                ->values();
        }

        return $this->run(fn () => $service->apply($item, $actor, $candidate, $roles), 'Заявка отправлена.');
    }

    public function respond(Request $request, string $tournament, TournamentAdmission $admission, TournamentAdmissionService $service, CurrentActorResolver $actors): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in([
            TournamentAdmissionStatusEnum::ACCEPTED->value,
            TournamentAdmissionStatusEnum::DECLINED->value,
        ])], 'entry_id' => ['nullable', 'integer', 'exists:tournament_entries,id'], 'response_comment' => ['nullable', 'string', 'max:1000']]);
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();
        $actor = $actors->resolveForRequest($request) ?? abort(403);
        if ($admission->source === TournamentAdmissionSourceEnum::ON_SITE && $data['decision'] === TournamentAdmissionStatusEnum::ACCEPTED->value) {
            $onSite = app(TournamentOnSiteRegistrationService::class);
            $entry = isset($data['entry_id']) ? TournamentEntry::query()->findOrFail($data['entry_id']) : null;

            return $this->run(fn () => $onSite->accept($item, $admission, $actor, $entry), 'Участник допущен к турниру.');
        }
        if ($admission->source === TournamentAdmissionSourceEnum::ON_SITE && $data['decision'] === TournamentAdmissionStatusEnum::DECLINED->value) {
            return $this->run(fn () => app(TournamentOnSiteRegistrationService::class)->decline($item, $admission, $actor, $data['response_comment'] ?? null), 'Заявка отклонена.');
        }

        return $this->run(fn () => $service->respond($item, $admission, $actor, TournamentAdmissionStatusEnum::from($data['decision']), $data['response_comment'] ?? null), 'Ответ сохранён.');
    }

    public function toggleOnSite(Request $request, string $tournament, CurrentActorResolver $actors, TournamentAccess $access): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();
        $actor = $actors->resolveForRequest($request) ?? abort(403);
        try {
            DB::transaction(function () use ($item, $actor, $access, $data): void {
                $locked = Tournament::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                $access->assertAllows($locked, $actor, TournamentPermissionEnum::MANAGE_GAMES);
                if ($locked->recruitment_mode !== TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT) {
                    throw new InvalidArgumentException('Регистрация на месте доступна только для турнира с отдельными игроками.');
                }
                if ((bool) $data['enabled'] && ($locked->format?->sideSize() ?? 1) === 1) {
                    throw new InvalidArgumentException('Регистрация на месте пока доступна для balanced-турниров 3×3 и 5×5.');
                }
                if ((bool) $data['enabled'] && $locked->phase() === TournamentPhaseEnum::COMPLETED) {
                    throw new InvalidArgumentException('Нельзя включить регистрацию на месте для завершённого турнира.');
                }
                $locked->forceFill(['allows_on_site_registration' => (bool) $data['enabled']])->save();
            });
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', (bool) $data['enabled'] ? 'Регистрация на месте включена.' : 'Регистрация на месте закрыта.');
    }

    public function revoke(Request $request, string $tournament, TournamentAdmission $admission, TournamentAdmissionService $service, CurrentActorResolver $actors): RedirectResponse
    {
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();

        return $this->run(fn () => $service->revoke($item, $admission, $actors->resolveForRequest($request) ?? abort(403)), 'Допуск отозван.');
    }

    public function blockOnSite(Request $request, string $tournament, TournamentAdmission $admission, TournamentOnSiteRegistrationService $service, CurrentActorResolver $actors): RedirectResponse
    {
        $data = $request->validate(['response_comment' => ['nullable', 'string', 'max:1000']]);
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();

        return $this->run(fn () => $service->block($item, $admission, $actors->resolveForRequest($request) ?? abort(403), $data['response_comment'] ?? null), 'Заявка отклонена, повторная регистрация участника заблокирована.');
    }

    public function lockPool(Request $request, string $tournament, TournamentParticipantPoolService $service, CurrentActorResolver $actors): RedirectResponse
    {
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();

        return $this->run(fn () => $service->lock($item, $actors->resolveForRequest($request) ?? abort(403)), 'Набор команд завершён.');
    }

    public function unlockPool(Request $request, string $tournament, TournamentParticipantPoolService $service, CurrentActorResolver $actors): RedirectResponse
    {
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();

        return $this->run(fn () => $service->unlock($item, $actors->resolveForRequest($request) ?? abort(403)), 'Набор команд возобновлён.');
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
