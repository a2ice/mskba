<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\AccountCheckForPresentationService;
use App\Modules\Identity\Application\UseCases\UpdateUserParticipationRolesHandler;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Presentation\Http\Requests\UpdateAccountParticipationRolesRequest;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
