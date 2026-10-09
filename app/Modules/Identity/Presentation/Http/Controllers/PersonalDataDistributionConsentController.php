<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Identity\Application\DTO\PrivacyConsentDTO;
use App\Modules\Identity\Application\Services\AccountCheckForPresentationService;
use App\Modules\Identity\Application\Services\PersonalDataDistributionConsentService;
use App\Modules\Identity\Application\UseCases\UpdatePersonalDataDistributionConsentHandler;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Models\UserConsent;
use App\Modules\Identity\Presentation\Http\Requests\UpdatePersonalDataDistributionConsentRequest;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PersonalDataDistributionConsentController extends Controller
{
    public function show(
        Request $request,
        AccountCheckForPresentationService $accountCheck,
        PersonalDataDistributionConsentService $consents,
    ): Response|RedirectResponse {
        $user = $accountCheck->handle($request->user())->canonical();

        if (! $consents->requiresSetup($user)) {
            return redirect()->route('account.settings');
        }

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

        $contacts = $user->identityContactsQuery()->whereNotNull('verified_at')
            ->whereIn('type', [
                ContactTypeEnum::EMAIL->value,
                ContactTypeEnum::TELEGRAM->value,
                ContactTypeEnum::VK->value,
            ])
            ->orderByDesc('is_primary')->get()
            ->unique(fn ($contact) => $contact->type->value)
            ->map(fn ($contact): array => [
                'type' => $contact->type->value,
                'label' => $contact->type->label(),
                'value' => $contact->displayValue(),
            ])
            ->values()->all();

        $viewData = [
            'distributionTypes' => UserPrivacySettingTypeEnum::distributionTypes(),
            'selectedTypeValues' => $selectedTypeValues,
            'isFirstSetup' => $consents->requiresSetup($user),
            'hasPlayerRole' => $user->hasActiveRole('player'),
            'privacyOptions' => $request->session()->getOldInput('privacy_options') ?? [],
            'hasActiveConsent' => $user->consents()
                ->where('type', UserConsent::TYPE_PERSONAL_DATA_DISTRIBUTION)
                ->whereNull('revoked_at')
                ->exists(),
            'notificationContacts' => $contacts,
            // Last-step onboarding should only ask for mandatory privacy.
            // Keep the experimental channel preview on the standalone page.
            'hideNotificationPreview' => $request->boolean('modal')
                && app(ThemeResolver::class)->active() === 'mskba_app',
        ];

        // A fixed fragment endpoint for the shared HTML form. It is
        // authenticated and only available while setup is required.
        if ($request->boolean('modal') && app(ThemeResolver::class)->active() === 'mskba_app') {
            return response()->view('theme::pages.account.partials.privacy-onboarding-content', $viewData);
        }

        return ThemeResolver::page('account.privacy-distribution', $viewData);
    }

    public function store(
        UpdatePersonalDataDistributionConsentRequest $request,
        AccountCheckForPresentationService $accountCheck,
        PersonalDataDistributionConsentService $consents,
        UpdatePersonalDataDistributionConsentHandler $handler,
    ): RedirectResponse|JsonResponse {
        $user = $accountCheck->handle($request->user())->canonical();

        if (! $consents->requiresSetup($user)) {
            return redirect()
                ->route('account.settings')
                ->with('status', 'Публичность настраивается на общей странице настроек.');
        }

        $selectedTypes = $request->selectedTypes();

        $evidence = $selectedTypes === [] ? null : new PrivacyConsentDTO(
            documentVersion: (string) config('legal.personal_data_distribution_consent_version'),
            acceptedAt: CarbonImmutable::now(),
            source: 'public_data_setup',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $handler->handle($user, $selectedTypes, $evidence, $request->privacyOptions());
        // The handler updates a locked copy. Refresh the in-session identity
        // so this request and subsequent reused guard instances see completion.
        $request->user()->refresh();

        $returnTo = (string) $request->session()->pull(
            'privacy.distribution.return_to',
            route('account'),
        );

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'success',
                'completed' => true,
                'redirect_url' => $returnTo,
                'message' => 'Регистрация завершена. Настройки сохранены.',
            ]);
        }

        return redirect()->to($returnTo)->with(
            'status',
            $selectedTypes === []
                ? 'Публичное распространение персональных данных отключено.'
                : 'Настройки публичности и отдельное согласие сохранены.',
        );
    }
}
