<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\AccountCheckForPresentationService;
use App\Modules\Identity\Application\UseCases\UpdateUserPrivacySettingsHandler;
use App\Modules\Identity\Presentation\Http\Requests\UpdateAccountPrivacySettingsRequest;
use Illuminate\Http\RedirectResponse;

final class UpdateAccountPrivacySettingsController extends Controller
{
    public function __invoke(
        UpdateAccountPrivacySettingsRequest $request,
        AccountCheckForPresentationService $accountCheck,
        UpdateUserPrivacySettingsHandler $updatePrivacySettings,
    ): RedirectResponse {
        $user = $accountCheck->handle($request->user());
        $updatePrivacySettings->handle($user, $request->settings());

        return redirect()
            ->route('account.settings')
            ->with('status', 'Настройки приватности сохранены.');
    }
}
