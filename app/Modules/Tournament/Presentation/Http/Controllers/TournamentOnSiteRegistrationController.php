<?php

namespace App\Modules\Tournament\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\DTO\PrivacyConsentDTO;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\ValueObjects\UsernameVO;
use App\Modules\Tournament\Application\Services\TournamentOnSiteRegistrationService;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionRoleEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionSourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPhaseEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class TournamentOnSiteRegistrationController extends Controller
{
    public function show(Request $request, string $tournament): Response
    {
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();

        $latestAdmission = $request->user() === null ? null : $item->admissions()
            ->where('user_id', $request->user()->id)
            ->where('source', TournamentAdmissionSourceEnum::ON_SITE->value)
            ->latest('id')->first();

        return ThemeResolver::page('tournaments.on-site-registration', [
            'tournament' => $item->loadMissing('createdByActor.user.profile'),
            'roles' => TournamentAdmissionRoleEnum::cases(),
            'available' => $item->allows_on_site_registration
                && $item->recruitment_mode === TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT
                && ($item->format?->sideSize() ?? 1) > 1
                && $item->phase() !== TournamentPhaseEnum::COMPLETED,
            'latestAdmission' => $latestAdmission,
            'hasActiveAdmission' => $latestAdmission !== null && in_array($latestAdmission->status->value, ['pending', 'accepted'], true),
            'isBlocked' => $request->user() !== null && $item->admissions()->where('user_id', $request->user()->id)->whereNotNull('blocked_at')->exists(),
        ]);
    }

    public function username(Request $request, string $tournament): JsonResponse
    {
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();
        abort_unless($item->allows_on_site_registration, 404);
        try {
            $username = UsernameVO::fromString((string) $request->query('username'))->value;
        } catch (InvalidArgumentException $exception) {
            return response()->json(['available' => false, 'message' => $exception->getMessage()], 422);
        }

        $available = ! User::query()->where('username', $username)->exists();

        return response()->json(['available' => $available, 'username' => $username, 'message' => $available ? 'Логин свободен.' : 'Этот логин уже занят.'], $available ? 200 : 422);
    }

    public function store(Request $request, string $tournament, TournamentOnSiteRegistrationService $service, CurrentActorResolver $actors): RedirectResponse
    {
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();
        if ($request->user() === null) {
            try {
                $request->merge(['username' => UsernameVO::fromString((string) $request->input('username'))->value]);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['username' => $exception->getMessage()]);
            }
        }
        $guestRules = $request->user() === null
            ? [
                'username' => ['required', 'string', 'max:32', 'unique:users,username'],
                'privacy_consent' => ['required', 'accepted'],
            ]
            : [];
        $data = $request->validate([
            ...$guestRules,
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'distinct', Rule::enum(TournamentAdmissionRoleEnum::class)],
        ]);
        $roles = collect($data['roles'])->map(fn (string $role) => TournamentAdmissionRoleEnum::from($role))->values();
        try {
            if ($request->user() === null) {
                $result = $service->registerAndApply($item, (string) $data['username'], $roles, new PrivacyConsentDTO(
                    documentVersion: (string) config('legal.privacy_policy_version'), acceptedAt: CarbonImmutable::now(),
                    source: 'tournament_on_site_registration', ipAddress: $request->ip(), userAgent: $request->userAgent(),
                ));
                Auth::login($result['user']);
                $request->session()->regenerate();
            } else {
                $actor = $actors->resolveForRequest($request) ?? abort(403);
                $service->apply($item, $request->user(), $actor, $roles);
            }
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('tournaments.on-site.show', $item->routeIdentifier())->with('status', 'Заявка отправлена ответственному за турнир.');
    }
}
