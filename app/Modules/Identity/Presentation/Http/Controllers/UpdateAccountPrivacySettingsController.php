<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\DTO\PrivacyConsentDTO;
use App\Modules\Identity\Application\Services\AccountCheckForPresentationService;
use App\Modules\Identity\Application\UseCases\UpdateUserPrivacySettingsHandler;
use App\Modules\Identity\Presentation\Http\Requests\UpdateAccountPrivacySettingsRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

final class UpdateAccountPrivacySettingsController extends Controller
{
    public function __invoke(
        UpdateAccountPrivacySettingsRequest $request,
        AccountCheckForPresentationService $accountCheck,
        UpdateUserPrivacySettingsHandler $updatePrivacySettings,
    ): RedirectResponse {
        $user = $accountCheck->handle($request->user());
        $distributionConsentEvidence = $request->distributionConsentAccepted()
            ? new PrivacyConsentDTO(
                documentVersion: (string) config('legal.personal_data_distribution_consent_version'),
                acceptedAt: CarbonImmutable::now(),
                source: 'public_data_settings',
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            )
            : null;

        $updatePrivacySettings->handle(
            $user,
            $request->settings(),
            $request->messengerNotifications(),
            $request->emailNotifications(),
            $distributionConsentEvidence,
        );

        return redirect()
            ->route('account.settings')
            ->with('status', 'Настройки приватности и уведомлений сохранены.');
    }
}
