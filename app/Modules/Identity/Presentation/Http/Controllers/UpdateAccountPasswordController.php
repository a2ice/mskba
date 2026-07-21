<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\AccountCheckForPresentationService;
use App\Modules\Identity\Application\UseCases\SetUserPasswordHandler;
use App\Modules\Identity\Presentation\Http\Requests\UpdateAccountPasswordRequest;
use Illuminate\Http\RedirectResponse;

final class UpdateAccountPasswordController extends Controller
{
    public function __invoke(
        UpdateAccountPasswordRequest $request,
        AccountCheckForPresentationService $accountCheck,
        SetUserPasswordHandler $setUserPassword,
    ): RedirectResponse {
        $user = $accountCheck->handle($request->user());
        $validated = $request->validated();
        $hadPassword = $user->password !== null;

        $setUserPassword->handle(
            $user,
            $validated['current_password'] ?? null,
            $validated['password'],
        );

        return redirect()
            ->route('account.settings')
            ->with('status', $hadPassword ? 'Пароль изменён.' : 'Пароль установлен.');
    }
}
