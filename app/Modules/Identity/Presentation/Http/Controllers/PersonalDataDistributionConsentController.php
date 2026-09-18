<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\DTO\PrivacyConsentDTO;
use App\Modules\Identity\Application\Services\AccountCheckForPresentationService;
use App\Modules\Identity\Application\Services\PersonalDataDistributionConsentService;
use App\Modules\Identity\Application\UseCases\UpdatePersonalDataDistributionConsentHandler;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Models\UserConsent;
use App\Modules\Identity\Presentation\Http\Requests\UpdatePersonalDataDistributionConsentRequest;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PersonalDataDistributionConsentController extends Controller
{
    public function show(
        Request $request,
        AccountCheckForPresentationService $accountCheck,
        PersonalDataDistributionConsentService $consents,
    ): Response {
        $user = $accountCheck->handle($request->user())->canonical();

        if ($request->query('return') === 'settings') {
            $request->session()->put('privacy.distribution.return_to', route('account.settings'));
        }

        $oldPublic = old('public');
        $selectedTypeValues = $oldPublic === null
            ? $consents->selectedForForm($user)
            : collect(is_array($oldPublic) ? $oldPublic : [])
                ->filter(fn (mixed $value): bool => in_array($value, [1, '1', true, 'on'], true))
                ->keys()
                ->all();

        return ThemeResolver::page('account.privacy-distribution', [
            'distributionTypes' => UserPrivacySettingTypeEnum::distributionTypes(),
            'selectedTypeValues' => $selectedTypeValues,
            'isFirstSetup' => $consents->requiresSetup($user),
            'hasActiveConsent' => $user->consents()
                ->where('type', UserConsent::TYPE_PERSONAL_DATA_DISTRIBUTION)
                ->whereNull('revoked_at')
                ->exists(),
        ]);
    }

    public function store(
        UpdatePersonalDataDistributionConsentRequest $request,
        AccountCheckForPresentationService $accountCheck,
        PersonalDataDistributionConsentService $consents,
        UpdatePersonalDataDistributionConsentHandler $handler,
    ): RedirectResponse {
        $user = $accountCheck->handle($request->user())->canonical();
        $selectedTypes = $request->selectedTypes();
        $wasFirstSetup = $consents->requiresSetup($user);

        $evidence = $selectedTypes === [] ? null : new PrivacyConsentDTO(
            documentVersion: (string) config('legal.personal_data_distribution_consent_version'),
            acceptedAt: CarbonImmutable::now(),
            source: $wasFirstSetup ? 'public_data_setup' : 'public_data_settings',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $handler->handle($user, $selectedTypes, $evidence);

        $returnTo = (string) $request->session()->pull(
            'privacy.distribution.return_to',
            route('account'),
        );

        return redirect()->to($returnTo)->with(
            'status',
            $selectedTypes === []
                ? 'Публичное распространение персональных данных отключено.'
                : 'Настройки публичности и отдельное согласие сохранены.',
        );
    }
}
