<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contact\Application\Exceptions\ContactDeletionException;
use App\Modules\Contact\Application\Exceptions\ContactVerificationCooldownException;
use App\Modules\Contact\Application\Exceptions\ContactVerificationDeliveryException;
use App\Modules\Contact\Application\Exceptions\ContactVerificationException;
use App\Modules\Contact\Application\Strategies\EmailContactVerificationStrategy;
use App\Modules\Contact\Application\UseCases\ConfirmContactVerificationHandler;
use App\Modules\Contact\Application\UseCases\CreateContactForUserHandler;
use App\Modules\Contact\Application\UseCases\DeleteContactHandler;
use App\Modules\Contact\Application\UseCases\SetPrimaryContactForUserHandler;
use App\Modules\Contact\Application\UseCases\StartContactVerificationHandler;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Presentation\Http\Controllers\EmulateSocialContactController;
use App\Modules\Contact\Presentation\Http\Requests\ConfirmContactVerificationRequest;
use App\Modules\Contact\Presentation\Http\Requests\CreateAccountContactRequest;
use App\Modules\Contract\Application\UseCases\ListAccountContractsHandler;
use App\Modules\Contract\Application\UseCases\ShowAccountContractHandler;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Application\Services\AccountCheckForPresentationService;
use App\Modules\Identity\Application\Services\AccountConfirmationWizardService;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\Services\PersonalDataDistributionConsentService;
use App\Modules\Identity\Application\UseCases\CompleteAccountConfirmationWizardHandler;
use App\Modules\Identity\Domain\Enums\Participation\PlayerBodyTypeEnum;
use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\ProfileChangeRequest;
use App\Modules\Identity\Domain\Models\Participation\PlayerObjectiveAssessment;
use App\Modules\Identity\Domain\Models\Participation\PlayerSelfAssessment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Presentation\Http\Requests\CompleteAccountConfirmationWizardRequest;
use App\Modules\Notification\Application\Services\AccountConversationRail;
use App\Modules\Notification\Application\Services\SystemNotificationTimeline;
use App\Modules\Notification\Application\UseCases\CountNewUserNotificationsHandler;
use App\Modules\Notification\Application\UseCases\GetSystemConversationPreviewHandler;
use App\Modules\Notification\Application\UseCases\ListAccountConversationsHandler;
use App\Modules\Notification\Application\UseCases\ListNewUserNotificationsHandler;
use App\Modules\Notification\Application\UseCases\ListUserNotificationsHandler;
use App\Modules\Notification\Application\UseCases\MarkAllUserNotificationsAsReadHandler;
use App\Modules\Notification\Application\UseCases\MarkUserNotificationAsReadHandler;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Presentation\Presenters\UserNotificationPresenter;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Venue\Application\UseCases\ListAccountVenuesHandler;
use App\Modules\Venue\Application\UseCases\ShowAccountVenueScheduleHandler;
use App\Modules\Venue\Application\UseCases\ShowVenueHandler;
use App\Modules\Venue\Application\UseCases\UpdateVenueScheduleHandler;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Presentation\Http\Requests\UpdateVenueScheduleRequest;
use App\Modules\VenueBooking\Application\Services\MinorAmountParser;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Modules\Vk\Domain\Models\VkAccount;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountCheckForPresentationService $accountCheckForPresentationService,
    ) {}

    public function index(AccountConfirmationWizardService $wizard): Response
    {
        try {
            $user = $this->accountCheckForPresentationService->handle(request()->user());
        } catch (\Exception $e) {
            return ThemeResolver::page('account.index', ['user' => null, 'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        $user->load('profile', 'participationRoles');
        $this->loadIdentityContacts($user);

        $data = [
            'user' => $user,
            'primaryContact' => $wizard->primaryContact($user),
            'primaryVerifiedContact' => $wizard->primaryVerifiedContact($user),
        ];

        if (app(ThemeResolver::class)->active() === 'mskba_app') {
            $canonical = $user->canonical();
            $data['dashboardWidgets'] = app(\App\Modules\Dashboard\Application\Services\AccountDashboardService::class)->forUser($canonical);
            $data['dashboardUser'] = $canonical;
            $data['dashboardUnread'] = app(\App\Modules\Notification\Application\UseCases\CountNewUserNotificationsHandler::class)->handle($canonical);
        }

        return ThemeResolver::page('account.index', $data);
    }

    public function confirmation(AccountConfirmationWizardService $wizard): Response
    {
        try {
            $user = $this->accountCheckForPresentationService->handle(request()->user());
        } catch (\Exception $e) {
            return ThemeResolver::page('account.confirmation', ['user' => null, 'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        $user->loadMissing(['profile', 'participationRoles']);
        $this->loadIdentityContacts($user);
        $primaryContact = $wizard->primaryContact($user);

        return ThemeResolver::page('account.confirmation', [
            'user' => $user,
            'steps' => $wizard->steps($user),
            'primaryContact' => $primaryContact,
            'primaryVerifiedContact' => $wizard->primaryVerifiedContact($user),
            'primaryContactPendingVerification' => $primaryContact?->verifications->first(),
            'primaryContactResendSeconds' => EmailContactVerificationStrategy::secondsUntilResend(
                $primaryContact?->verifications->first(),
            ),
            'genders' => UserGenderEnum::cases(),
            'telegramBotUsername' => ltrim(trim((string) config('telegram.bot_username')), '@'),
            'vkEnabled' => trim((string) config('vk.app_id')) !== '',
            'contactEmulationEnabled' => EmulateSocialContactController::enabled(),
        ]);
    }

    public function completeConfirmation(
        CompleteAccountConfirmationWizardRequest $request,
        CompleteAccountConfirmationWizardHandler $completeAccountConfirmationWizard,
    ): RedirectResponse {
        $user = $this->accountCheckForPresentationService->handle($request->user());

        $updatedUser = $completeAccountConfirmationWizard->handle(
            $user,
            $request->firstName(),
            $request->lastName(),
            $request->birthDate(),
            $request->gender(),
        );

        return redirect()
            ->route($updatedUser->status === UserStatusEnum::CONFIRMED ? 'account' : 'account.confirmation')
            ->with('status', 'Аккаунт подтвержден.');
    }

    public function storeConfirmationContact(
        CreateAccountContactRequest $request,
        CreateContactForUserHandler $createContactForUser,
        StartContactVerificationHandler $startContactVerification,
    ): RedirectResponse|JsonResponse {
        $user = $this->accountCheckForPresentationService->handle($request->user());
        $data = $request->toDTO();

        // Telegram and VK must be verified via their signed identity providers.
        // Never let a typed username count as a verified login identity.
        if ($data->type !== ContactTypeEnum::EMAIL) {
            $message = 'Для Telegram и VK используйте официальную авторизацию. Подтверждение телефона пока недоступно.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 422)
                : redirect()->route('account.confirmation')->with('error', $message);
        }

        try {
            $existing = $user->identityContactsQuery()
                ->where('type', ContactTypeEnum::EMAIL)
                ->where('value', $data->value)
                ->first();
            if ($existing?->hasBeenVerified()) {
                throw new InvalidArgumentException('Этот Email уже подтверждён в твоём аккаунте.');
            }
            $contact = $existing ?? $createContactForUser->handle($user, $data);
            $startContactVerification->handle($contact);
        } catch (ContactVerificationCooldownException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'contact_id' => $contact->id,
                    'seconds_left' => $e->secondsLeft,
                ], 429);
            }

            return redirect()->route('account.confirmation')
                ->with('info', $e->getMessage())
                ->with('contactVerificationCooldownSeconds', $e->secondsLeft);
        } catch (ContactVerificationDeliveryException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 503)
                : redirect()->route('account.confirmation')->with('error', $e->getMessage());
        } catch (InvalidArgumentException|LogicException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : redirect()->route('account.confirmation')->withInput()->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Код подтверждения отправлен.',
                'contact_id' => $contact->id,
                'value' => $contact->displayValue(),
                'seconds_left' => 60,
            ]);
        }

        return redirect()->route('account.confirmation')
            ->with('status', 'Контакт добавлен. Код подтверждения отправлен.');
    }

    public function startConfirmationContactVerification(
        Contact $contact,
        StartContactVerificationHandler $startContactVerification,
    ): RedirectResponse|JsonResponse {
        $request = request();
        $user = $this->accountCheckForPresentationService->handle($request->user());

        abort_unless($user->ownsIdentityContact($contact), 404);
        abort_unless($contact->type === ContactTypeEnum::EMAIL, 422);

        try {
            $startContactVerification->handle($contact);
        } catch (ContactVerificationCooldownException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'seconds_left' => $e->secondsLeft], 429);
            }

            return redirect()->route('account.confirmation')
                ->with('info', $e->getMessage())
                ->with('contactVerificationCooldownSeconds', $e->secondsLeft);
        } catch (ContactVerificationDeliveryException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 503)
                : redirect()->route('account.confirmation')->with('error', $e->getMessage());
        } catch (LogicException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : redirect()->route('account.confirmation')->with('error', $e->getMessage());
        }

        return $request->expectsJson()
            ? response()->json(['message' => 'Новый код отправлен.', 'seconds_left' => 60])
            : redirect()->route('account.confirmation')->with('status', 'Код подтверждения отправлен.');
    }

    public function confirmConfirmationContactVerification(
        ConfirmContactVerificationRequest $request,
        Contact $contact,
        ConfirmContactVerificationHandler $confirmContactVerification,
    ): RedirectResponse|JsonResponse {
        $user = $this->accountCheckForPresentationService->handle($request->user());

        abort_unless($user->ownsIdentityContact($contact), 404);

        try {
            $confirmContactVerification->handle($contact, $request->toDTO());
        } catch (ContactVerificationException|LogicException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : redirect()->route('account.confirmation')->withInput()->with('error', $e->getMessage());
        }

        return $request->expectsJson()
            ? response()->json(['message' => 'Контакт подтверждён.', 'redirect_url' => route('account.confirmation')])
            : redirect()->route('account.confirmation')->with('status', 'Контакт подтвержден.');
    }

    public function settings(PersonalDataDistributionConsentService $distributionConsents): Response
    {
        try {
            $user = $this->accountCheckForPresentationService->handle(request()->user());
        } catch (\Exception $e) {
            return ThemeResolver::page('account.settings', ['user' => null, 'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        $user->load([
            'privacySettings.allowedUsers.profile',
        ]);
        $privacySettings = $user->privacySettings->keyBy(
            fn ($setting): string => $setting->type->value,
        );
        $oldPrivacy = request()->old('privacy');
        $oldAllowedUserIds = collect(is_array($oldPrivacy) ? $oldPrivacy : [])
            ->flatMap(fn (mixed $setting): array => is_array($setting)
                ? array_map('intval', (array) ($setting['allowed_user_ids'] ?? []))
                : [])
            ->unique()
            ->values();
        $oldAllowedUsers = $oldAllowedUserIds->isEmpty()
            ? collect()
            : User::query()
                ->with('profile')
                ->whereKey($oldAllowedUserIds->all())
                ->get()
                ->keyBy(fn (User $allowedUser): int => (int) $allowedUser->getKey());
        $privacyAllowedUsers = collect(UserPrivacySettingTypeEnum::cases())
            ->mapWithKeys(function (UserPrivacySettingTypeEnum $type) use (
                $oldPrivacy,
                $oldAllowedUsers,
                $privacySettings,
            ): array {
                if (is_array($oldPrivacy) && array_key_exists($type->value, $oldPrivacy)) {
                    $ids = array_map(
                        'intval',
                        (array) ($oldPrivacy[$type->value]['allowed_user_ids'] ?? []),
                    );

                    return [
                        $type->value => collect($ids)
                            ->map(fn (int $id) => $oldAllowedUsers->get($id))
                            ->filter()
                            ->values(),
                    ];
                }

                return [
                    $type->value => $privacySettings->get($type->value)?->allowedUsers ?? collect(),
                ];
            });

        $distributionConsentAllowedTypes = $distributionConsents->allowedTypeValues($user);

        return ThemeResolver::page('account.settings', [
            'user' => $user,
            'privacySettingTypes' => UserPrivacySettingTypeEnum::cases(),
            'privacyVisibilities' => UserPrivacyVisibilityEnum::cases(),
            'privacySettings' => $privacySettings,
            'privacyAllowedUsers' => $privacyAllowedUsers,
            'distributionConsentAllowedTypes' => $distributionConsentAllowedTypes,
            'distributionConsentEnforced' => $distributionConsents->isEnforcedFor($user),
            'distributionConsentRequiresRefresh' => $distributionConsents->needsAcceptance(
                $user,
                $distributionConsentAllowedTypes,
            ),
        ]);
    }

    public function notifications(
        ListUserNotificationsHandler $listUserNotifications,
        CountNewUserNotificationsHandler $countNewUserNotifications,
        GetSystemConversationPreviewHandler $systemConversation,
        ListAccountConversationsHandler $conversations,
        CurrentActorResolver $actors,
    ): Response {
        try {
            $user = $this->accountCheckForPresentationService->handle(request()->user());
        } catch (\Exception $e) {
            return ThemeResolver::page('account.notifications', ['user' => null, 'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        return ThemeResolver::page('account.notifications', [
            'notifications' => $listUserNotifications->handle($user),
            'newNotificationsCount' => $countNewUserNotifications->handle($user),
            'systemConversation' => $systemConversation->handle($user),
            'applicationConversations' => $conversations->handle($user, $actors->resolveForRequest(request())),
        ]);
    }

    public function systemNotificationConversation(
        ListUserNotificationsHandler $listUserNotifications,
        GetSystemConversationPreviewHandler $systemConversation,
    ): Response|RedirectResponse {
        if (app(ThemeResolver::class)->active() !== 'mskba_app') {
            return redirect()->route('account.notifications');
        }

        $user = $this->accountCheckForPresentationService->handle(request()->user());
        $page = app(SystemNotificationTimeline::class)->page($user);

        return ThemeResolver::page('account.notification-conversation', [
            'notifications' => $page['entries'], 'nextBefore' => $page['next_before'],
            'systemConversation' => $systemConversation->handle($user),
            'conversationCards' => app(AccountConversationRail::class)->items($user, request()),
            'activeConversationId' => 'system',
        ]);
    }

    public function readNotification(
        UserNotification $notification,
        MarkUserNotificationAsReadHandler $markUserNotificationAsRead,
    ): JsonResponse|RedirectResponse {
        $request = request();
        $user = $this->accountCheckForPresentationService->handle($request->user());
        $markUserNotificationAsRead->handle($user, $notification);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Уведомление прочитано.',
                'notification_id' => $notification->id,
                'unread_count' => app(CountNewUserNotificationsHandler::class)->handle($user),
            ]);
        }

        return redirect()
            ->route('account.notifications')
            ->with('status', 'Уведомление прочитано.');
    }

    public function newNotifications(
        ListNewUserNotificationsHandler $listNewUserNotifications,
        CountNewUserNotificationsHandler $countNewUserNotifications,
        UserNotificationPresenter $presenter,
    ): JsonResponse {
        $user = $this->accountCheckForPresentationService->handle(request()->user());

        // Cursor pagination avoids losing messages when unread notifications
        // are marked read while the client is loading its toast list.
        $params = request()->validate(['before_id' => ['sometimes', 'integer', 'min:1']]);
        $notifications = $listNewUserNotifications->handle($user, 20, $params['before_id'] ?? null);

        return response()->json([
            'notifications' => $notifications
                ->map(fn (UserNotification $notification): array => $presenter->present($notification))
                ->values(),
            'unread_count' => $countNewUserNotifications->handle($user),
            'next_before_id' => $notifications->count() === 20 ? $notifications->last()->id : null,
        ]);
    }

    public function readAllNotifications(
        MarkAllUserNotificationsAsReadHandler $markAllUserNotificationsAsRead,
    ): JsonResponse|RedirectResponse {
        $request = request();
        $user = $this->accountCheckForPresentationService->handle($request->user());
        $updatedCount = $markAllUserNotificationsAsRead->handle($user);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $updatedCount > 0 ? 'Все уведомления прочитаны.' : 'Новых уведомлений нет.',
                'unread_count' => 0,
            ]);
        }

        return redirect()
            ->route('account.notifications')
            ->with('status', $updatedCount > 0
                ? 'Все новые уведомления отмечены как прочитанные.'
                : 'Новых уведомлений нет.');
    }

    public function contacts(): Response
    {
        try {
            $user = $this->accountCheckForPresentationService->handle(request()->user());
        } catch (\Exception $e) {
            return ThemeResolver::page('account.contacts', ['user' => null, 'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        $this->loadIdentityContacts($user);

        $identityIds = $user->identityIds();
        $linkedTelegramAccount = TelegramAccount::query()
            ->whereIn('user_id', $identityIds)
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$user->id])
            ->orderByDesc('last_auth_at')
            ->orderByDesc('updated_at')
            ->first();
        $linkedVkAccount = VkAccount::query()
            ->whereIn('user_id', $identityIds)
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$user->id])
            ->orderByDesc('last_auth_at')
            ->orderByDesc('updated_at')
            ->first();

        return ThemeResolver::page('account.contacts', [
            'user' => $user,
            'contactTypes' => array_values(array_filter(
                ContactTypeEnum::cases(),
                static fn (ContactTypeEnum $type): bool => ! in_array($type, [
                    ContactTypeEnum::TELEGRAM,
                    ContactTypeEnum::VK,
                ], true),
            )),
            'contactResendCooldowns' => $user->contacts->mapWithKeys(fn (Contact $contact): array => [
                $contact->id => EmailContactVerificationStrategy::secondsUntilResend(
                    $contact->verifications->first(),
                ),
            ])->all(),
            'linkedTelegramAccount' => $linkedTelegramAccount,
            'linkedVkAccount' => $linkedVkAccount,
            'telegramBotUsername' => ltrim(trim((string) config('telegram.bot_username')), '@'),
            'vkEnabled' => trim((string) config('vk.app_id')) !== '',
            'contactEmulationEnabled' => EmulateSocialContactController::enabled(),
        ]);
    }

    public function storeContact(
        CreateAccountContactRequest $request,
        CreateContactForUserHandler $createContactForUser,
    ): RedirectResponse|JsonResponse {
        $user = $this->accountCheckForPresentationService->handle($request->user());
        $contactData = $request->toDTO();

        // Telegram and VK contacts can be created only by their signed identity flows.
        // User-supplied handles must never enter the identity contact list.
        if (! in_array($contactData->type, [ContactTypeEnum::EMAIL, ContactTypeEnum::PHONE], true)) {
            $message = 'Telegram и VK добавляются только через официальную авторизацию.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 422)
                : redirect()->route('account.contacts')->with('error', $message);
        }

        try {
            $contact = $createContactForUser->handle($user, $contactData);
        } catch (InvalidArgumentException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : redirect()->route('account.contacts')->withInput()->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Контакт добавлен.',
                'contact' => [
                    'id' => $contact->id,
                    'type' => $contact->type->value,
                    'value' => $contact->displayValue(),
                    'is_verified' => $contact->hasBeenVerified(),
                    'is_primary' => (bool) $contact->is_primary,
                ],
            ], 201);
        }

        return redirect()->route('account.contacts')->with('status', 'Контакт добавлен.');
    }

    public function setPrimaryContact(Contact $contact, SetPrimaryContactForUserHandler $setPrimaryContact): RedirectResponse
    {
        $user = $this->accountCheckForPresentationService->handle(request()->user());

        abort_unless(
            $user->ownsIdentityContact($contact),
            404,
        );

        $setPrimaryContact->handle($user, $contact);

        return redirect()
            ->route('account.contacts')
            ->with('status', 'Основной контакт обновлен.');
    }

    public function startContactVerification(
        Contact $contact,
        StartContactVerificationHandler $startContactVerification,
    ): RedirectResponse|JsonResponse {
        $request = request();
        $user = $this->accountCheckForPresentationService->handle($request->user());

        abort_unless(
            $user->ownsIdentityContact($contact),
            404,
        );

        try {
            $verification = $startContactVerification->handle($contact);
        } catch (ContactVerificationCooldownException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'seconds_left' => $e->secondsLeft,
                ], 429);
            }

            return redirect()
                ->route('account.contacts')
                ->with('info', $e->getMessage())
                ->with('contactVerificationFeedbackContactId', $contact->id)
                ->with('contactVerificationCooldownSeconds', $e->secondsLeft);
        } catch (ContactVerificationDeliveryException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 503);
            }

            return redirect()
                ->route('account.contacts')
                ->with('error', $e->getMessage())
                ->with('contactVerificationFeedbackContactId', $contact->id);
        } catch (LogicException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return redirect()
                ->route('account.contacts')
                ->with('error', $e->getMessage())
                ->with('contactVerificationFeedbackContactId', $contact->id);
        } catch (\Throwable $e) {
            Log::error('Contact verification request failed', [
                'contact_id' => $contact->id,
                'exception' => $e::class,
            ]);
            $message = 'Не удалось запросить код подтверждения. Попробуйте позже.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 500);
            }

            return redirect()
                ->route('account.contacts')
                ->with('error', $message)
                ->with('contactVerificationFeedbackContactId', $contact->id);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Код подтверждения отправлен.',
                'seconds_left' => EmailContactVerificationStrategy::secondsUntilResend($verification),
            ]);
        }

        return redirect()
            ->route('account.contacts')
            ->with('status', 'Код подтверждения отправлен.');
    }

    public function confirmContactVerification(
        ConfirmContactVerificationRequest $request,
        Contact $contact,
        ConfirmContactVerificationHandler $confirmContactVerification,
    ): RedirectResponse|JsonResponse {
        $user = $this->accountCheckForPresentationService->handle($request->user());
        abort_unless($user->ownsIdentityContact($contact), 404);

        try {
            $confirmContactVerification->handle($contact, $request->toDTO());
        } catch (ContactVerificationException|LogicException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return redirect()->route('account.contacts')->withInput()
                ->with('error', $e->getMessage())
                ->with('contactVerificationFeedbackContactId', $contact->id);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Контакт подтверждён.',
                'contact_id' => $contact->id, 'verified' => $contact->refresh()->hasBeenVerified()]);
        }

        return redirect()->route('account.contacts')->with('status', 'Контакт подтвержден.');
    }

    public function destroyContact(Contact $contact, DeleteContactHandler $deleteContact): RedirectResponse
    {
        $user = $this->accountCheckForPresentationService->handle(request()->user());

        abort_unless(
            $user->ownsIdentityContact($contact),
            404,
        );

        try {
            $deleteContact->handle($contact);
        } catch (ContactDeletionException $e) {
            return redirect()
                ->route('account.contacts')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('account.contacts')
            ->with('status', 'Контакт удален.');
    }

    public function participationRole(string $role): Response
    {
        $roleEnum = UserParticipationRoleEnum::tryFrom($role);

        abort_if($roleEnum === null, 404);

        try {
            $user = $this->accountCheckForPresentationService->handle(request()->user());
        } catch (\Exception $e) {
            return ThemeResolver::page('account.participation-role', ['user' => null, 'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        $user->loadMissing('playerProfile.positions', 'playerProfile.selfAssessment', 'playerObjectiveAssessment');

        $participationRole = $user->participationRoles()
            ->where('role', $roleEnum->value)
            ->first();

        abort_if($participationRole === null, 404);

        $playerTeams = collect();
        if ($roleEnum === UserParticipationRoleEnum::PLAYER) {
            $identityIds = $user->identityIds();
            $playerTeams = Team::query()
                ->whereNull('temporary_for_event_id')
                ->whereHas('memberships', fn ($memberships) => $memberships
                    ->whereIn('user_id', $identityIds)
                    ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
                    ->withSportRole(TeamMemberTypeEnum::PLAYER)
                    ->whereHas('contract', fn ($contract) => $contract
                        ->where('status', ContractStatusEnum::ACTIVE->value)))
                ->orderBy('name')
                ->get(['id', 'name', 'colors']);
        }

        // The player role reuses the profile editor, including existing moderation
        // states and permissions. Only load these details for the MSKBA App UI.
        $personalData = [];
        if ($roleEnum === UserParticipationRoleEnum::PLAYER
            && app(ThemeResolver::class)->active() === 'mskba_app') {
            $canonical = $user->canonical()->loadMissing('profile');
            $requests = ProfileChangeRequest::query()->where('user_id', $canonical->id);
            $personalData = [
                'personalProfile' => $canonical->profile,
                'profileConfirmed' => $canonical->isConfirmed(),
                'genders' => UserGenderEnum::cases(),
                'latestProfileChangeRequest' => (clone $requests)->latest('id')->first(),
                'activeProfileChangeRequest' => (clone $requests)
                    ->whereIn('status', [ProfileChangeRequest::PENDING, ProfileChangeRequest::BLOCKED])
                    ->first(),
            ];
        }

        return ThemeResolver::page('account.participation-role', array_merge([
            'user' => $user,
            'participationRole' => $participationRole,
            'role' => $roleEnum,
            'title' => 'Роль в проекте',
            'playerPositions' => PlayerPositionEnum::cases(),
            'playerBodyTypes' => PlayerBodyTypeEnum::cases(),
            'playerSkills' => PlayerSelfAssessment::SKILLS,
            'objectivePlayerSkills' => PlayerObjectiveAssessment::SKILLS,
            'playerTeams' => $playerTeams,
        ], $personalData));
    }

    public function contracts(ListAccountContractsHandler $listAccountContracts): Response
    {
        try {
            $user = $this->accountCheckForPresentationService->handle(request()->user());
        } catch (\Exception $e) {
            return ThemeResolver::page('account.contracts', ['user' => null, 'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        return ThemeResolver::page('account.contracts', [
            'contracts' => $listAccountContracts->handle($user),
        ]);
    }

    public function contract(string $number, ShowAccountContractHandler $showAccountContract): Response
    {
        try {
            $user = $this->accountCheckForPresentationService->handle(request()->user());
        } catch (\Exception $e) {
            return ThemeResolver::page('account.contract', ['user' => null, 'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        try {
            $contract = $showAccountContract->handle($number, $user);
        } catch (\Exception $e) {
            return ThemeResolver::page('account.contract', ['contract' => null, 'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        return ThemeResolver::page('account.contract', [
            'contract' => $contract,
        ]);
    }

    public function venues(ListAccountVenuesHandler $listAccountVenues): Response
    {
        try {
            $user = $this->accountCheckForPresentationService->handle(request()->user());
        } catch (\Exception $e) {
            return ThemeResolver::page('account.venues', ['user' => null, 'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        return ThemeResolver::page('account.venues', [
            'venues' => $listAccountVenues->handle($user),
        ]);
    }

    public function showVenue(string $alias, ShowVenueHandler $showVenue): Response
    {
        try {
            $user = $this->accountCheckForPresentationService->handle(request()->user());
        } catch (\Exception $e) {
            return ThemeResolver::page('account.venue', ['user' => null, 'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        try {
            $venue = $showVenue->handle($alias, $user);
        } catch (\Exception $e) {
            return ThemeResolver::page('account.venue', ['venue' => null, 'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        return ThemeResolver::page('account.venue', [
            'venue' => $venue,
        ]);
    }

    public function editVenueSchedule(string $alias, ShowAccountVenueScheduleHandler $showVenueSchedule): Response
    {
        try {
            $user = $this->accountCheckForPresentationService->handle(request()->user());
            $venue = $showVenueSchedule->handle($alias, $user);
        } catch (\Exception $e) {
            return ThemeResolver::page('account.venue-schedule', ['venue' => null, 'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        return ThemeResolver::page('account.venue-schedule', [
            'venue' => $venue,
            'scheduleRows' => $this->venueScheduleRows($venue),
            'scheduleExceptions' => $this->venueScheduleExceptions($venue),
            'weekDays' => $this->weekDays(),
            'bookingPolicy' => $policy = VenueBookingPolicy::query()
                ->where('venue_id', $venue->id)
                ->where('active_marker', true)
                ->first(),
            'slotPriceRows' => $this->venueSlotPriceRows($venue, $policy),
        ]);
    }

    public function updateVenueSchedule(
        UpdateVenueScheduleRequest $request,
        string $alias,
        UpdateVenueScheduleHandler $updateVenueSchedule,
        MinorAmountParser $amounts,
    ): RedirectResponse {
        $user = $this->accountCheckForPresentationService->handle($request->user());

        try {
            $policy = VenueBookingPolicy::query()
                ->where('venue_id', $this->venueIdForAlias($alias))
                ->where('active_marker', true)
                ->first();
            $slotPrices = $request->has('slot_prices')
                ? $this->parseSlotPrices($request->slotPrices(), $policy, $amounts)
                : null;
            $updateVenueSchedule->handle(
                alias: $alias,
                user: $user,
                timezone: $request->timezone(),
                intervalsByDay: $request->intervalsByDay(),
                exceptions: $request->exceptions(),
                operationalStatus: $request->operationalStatus(),
                slotPrices: $slotPrices,
            );
        } catch (\Exception $e) {
            return redirect()
                ->route('account.venues.schedule.edit', $alias)
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('account.venues.schedule.edit', $alias)
            ->with('status', 'Расписание сохранено.');
    }

    /**
     * @return array<int, string>
     */
    private function weekDays(): array
    {
        return [
            1 => 'Понедельник',
            2 => 'Вторник',
            3 => 'Среда',
            4 => 'Четверг',
            5 => 'Пятница',
            6 => 'Суббота',
            7 => 'Воскресенье',
        ];
    }

    private function loadIdentityContacts(User $user): void
    {
        $contacts = $user->identityContactsQuery()
            ->with(['verifications' => fn ($query) => $query
                ->where('status', ContactVerificationStatusEnum::PENDING->value)
                ->where('expires_at', '>', now())
                ->latest()])
            ->orderByDesc('is_primary')
            ->orderBy('type')
            ->orderBy('created_at')
            ->get();

        $user->setRelation('contacts', $contacts);
    }

    /**
     * @return array<int, array<int, array{starts_at: string, ends_at: string}>>
     */
    private function venueScheduleRows($venue): array
    {
        $rows = [];

        foreach (array_keys($this->weekDays()) as $dayOfWeek) {
            $intervals = $venue->schedule?->intervals
                ->where('day_of_week', $dayOfWeek)
                ->values()
                ->map(fn ($interval) => [
                    'starts_at' => substr((string) $interval->starts_at, 0, 5),
                    'ends_at' => substr((string) $interval->ends_at, 0, 5),
                ])
                ->all() ?? [];

            $rows[$dayOfWeek] = array_pad(array_slice($intervals, 0, 3), 3, [
                'starts_at' => '',
                'ends_at' => '',
            ]);
        }

        return $rows;
    }

    /** @return array<int, array{date: string, is_closed: bool, intervals: array<int, array{starts_at: string, ends_at: string}>}> */
    private function venueScheduleExceptions($venue): array
    {
        return $venue->schedule?->exceptions->map(fn ($exception): array => [
            'date' => $exception->date->format('Y-m-d'),
            'is_closed' => (bool) $exception->is_closed,
            'intervals' => $exception->intervals->map(fn ($interval): array => [
                'starts_at' => substr((string) $interval->starts_at, 0, 5),
                'ends_at' => substr((string) $interval->ends_at, 0, 5),
            ])->values()->all(),
        ])->values()->all() ?? [];
    }

    private function venueIdForAlias(string $alias): int
    {
        return (int) Venue::query()
            ->whereRouteIdentifier($alias)
            ->value('id');
    }

    private function formatMinorAmount(?int $amount): string
    {
        return $amount === null ? '' : number_format($amount / 100, 2, ',', '');
    }

    /** @return array<int, array<string, mixed>> */
    private function venueSlotPriceRows($venue, ?VenueBookingPolicy $policy): array
    {
        if ($policy === null || $policy->time_step_minutes < 1 || $venue->schedule === null) {
            return [];
        }

        $prices = $venue->scheduleSlotPrices()->get()
            ->keyBy(fn ($price): string => $price->day_of_week.'|'.substr((string) $price->starts_at, 0, 5));
        $rows = [];

        foreach (array_keys($this->weekDays()) as $dayOfWeek) {
            $seen = [];
            foreach ($venue->schedule->intervals->where('day_of_week', $dayOfWeek) as $interval) {
                $cursor = CarbonImmutable::createFromFormat('H:i', substr((string) $interval->starts_at, 0, 5));
                $end = CarbonImmutable::createFromFormat('H:i', substr((string) $interval->ends_at, 0, 5));
                while ($cursor->lessThan($end)) {
                    $startsAt = $cursor->format('H:i');
                    if (! isset($seen[$startsAt])) {
                        $price = $prices->get($dayOfWeek.'|'.$startsAt);
                        $rows[$dayOfWeek][] = [
                            'day_of_week' => $dayOfWeek,
                            'starts_at' => $startsAt,
                            'ends_at' => $cursor->addMinutes($policy->time_step_minutes)->min($end)->format('H:i'),
                            'whole_price' => $this->formatMinorAmount($price?->whole_price_per_step_minor),
                            'half_price' => $this->formatMinorAmount($price?->half_price_per_step_minor),
                        ];
                        $seen[$startsAt] = true;
                    }
                    $cursor = $cursor->addMinutes($policy->time_step_minutes);
                }
            }
        }

        return $rows;
    }

    /** @return array<int, array{day_of_week: int, starts_at: string, whole_price_per_step_minor: ?int, half_price_per_step_minor: ?int}> */
    private function parseSlotPrices(array $prices, ?VenueBookingPolicy $policy, MinorAmountParser $amounts): array
    {
        if ($policy === null) {
            return [];
        }

        return collect($prices)->map(fn (array $price): array => [
            'day_of_week' => $price['day_of_week'],
            'starts_at' => $price['starts_at'],
            'whole_price_per_step_minor' => $price['whole_price'] === null ? null : $amounts->parse($price['whole_price'], $policy->currency),
            'half_price_per_step_minor' => $price['half_price'] === null ? null : $amounts->parse($price['half_price'], $policy->currency),
        ])->all();
    }
}
