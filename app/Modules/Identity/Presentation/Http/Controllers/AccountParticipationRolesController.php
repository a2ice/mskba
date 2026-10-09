<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\AccountCheckForPresentationService;
use App\Modules\Identity\Application\UseCases\UpdateUserParticipationRolesHandler;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Presentation\Http\Requests\UpdateAccountParticipationRolesRequest;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

final class AccountParticipationRolesController extends Controller
{
    public function __construct(
        private readonly AccountCheckForPresentationService $accountCheckForPresentationService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->accountCheckForPresentationService->handle($request->user());
        $user->load('participationRoles');

        return ThemeResolver::page('account.roles', [
            'user' => $user,
            'roles' => UserParticipationRoleEnum::cases(),
            'activeRoleValues' => $user->participationRoles
                ->map(fn ($role): string => $role->role->value)
                ->all(),
            // Restore an active switch cooldown after refresh, instead of
            // allowing a click that can only produce the server's HTTP 429.
            'roleCooldownSeconds' => app(ThemeResolver::class)->active() === 'mskba_app'
                ? collect(UserParticipationRoleEnum::cases())
                    ->mapWithKeys(function (UserParticipationRoleEnum $role) use ($user): array {
                        $key = 'mskba-app:role:'.$user->id.':'.$role->value;

                        return [$role->value => RateLimiter::tooManyAttempts($key, 1)
                            ? RateLimiter::availableIn($key)
                            : 0];
                    })
                    ->all()
                : [],
        ]);
    }

    public function updateOne(Request $request, UpdateUserParticipationRolesHandler $handler, string $role): JsonResponse
    {
        // This action is exclusively for mskba_app's instant role switches.
        abort_unless(app(ThemeResolver::class)->active() === 'mskba_app', 404);
        abort_unless($request->expectsJson(), 406);

        $roleEnum = UserParticipationRoleEnum::tryFrom($role);
        abort_if($roleEnum === null, 404);

        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $rateKey = 'mskba-app:role:'.$request->user()->id.':'.$roleEnum->value;
        if (RateLimiter::tooManyAttempts($rateKey, 1)) {
            $retryAfter = max(1, RateLimiter::availableIn($rateKey));

            return response()->json([
                'message' => 'Подожди немного перед повторным изменением этой роли.',
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        $user = $handler->updateOne($request->user(), $roleEnum, (bool) $data['enabled']);
        RateLimiter::hit($rateKey, 5);

        return response()->json([
            'role' => $roleEnum->value,
            'enabled' => $user->participationRoles->contains(
                fn ($active): bool => $active->role === $roleEnum,
            ),
            'active_roles' => $user->participationRoles->map(fn ($active): string => $active->role->value)->values()->all(),
            'retry_after' => 5,
        ]);
    }

    public function update(
        UpdateAccountParticipationRolesRequest $request,
        UpdateUserParticipationRolesHandler $handler,
    ): RedirectResponse {
        $handler->handle($request->user(), $request->selectedRoles());

        return redirect()
            ->route('account.roles')
            ->with('status', 'Роли в проекте обновлены.');
    }
}
