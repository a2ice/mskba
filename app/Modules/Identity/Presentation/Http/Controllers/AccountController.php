<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contact\Application\Exceptions\ContactDeletionException;
use App\Modules\Contact\Application\Exceptions\ContactVerificationCooldownException;
use App\Modules\Contact\Application\Exceptions\ContactVerificationException;
use App\Modules\Contact\Application\UseCases\ConfirmContactVerificationHandler;
use App\Modules\Contact\Application\UseCases\CreateContactForUserHandler;
use App\Modules\Contact\Application\UseCases\DeleteContactHandler;
use App\Modules\Contact\Application\UseCases\SetPrimaryContactForUserHandler;
use App\Modules\Contact\Application\UseCases\StartContactVerificationHandler;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Presentation\Http\Requests\ConfirmContactVerificationRequest;
use App\Modules\Contact\Presentation\Http\Requests\CreateAccountContactRequest;
use App\Modules\Contract\Application\UseCases\ListAccountContractsHandler;
use App\Modules\Contract\Application\UseCases\ShowAccountContractHandler;
use App\Modules\Identity\Application\Services\AccountCheckForPresentationService;
use App\Modules\Identity\Application\Services\AccountConfirmationWizardService;
use App\Modules\Identity\Application\UseCases\CompleteAccountConfirmationWizardHandler;
use App\Modules\Identity\Domain\Enums\Participation\PlayerBodyTypeEnum;
use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Participation\PlayerObjectiveAssessment;
use App\Modules\Identity\Domain\Models\Participation\PlayerSelfAssessment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Presentation\Http\Requests\CompleteAccountConfirmationWizardRequest;
use App\Modules\Notification\Application\UseCases\CountNewUserNotificationsHandler;
use App\Modules\Notification\Application\UseCases\ListNewUserNotificationsHandler;
use App\Modules\Notification\Application\UseCases\ListUserNotificationsHandler;
use App\Modules\Notification\Application\UseCases\MarkAllUserNotificationsAsReadHandler;
use App\Modules\Notification\Application\UseCases\MarkUserNotificationAsReadHandler;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Presentation\Presenters\UserNotificationPresenter;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Venue\Application\UseCases\ListAccountVenuesHandler;
use App\Modules\Venue\Application\UseCases\ShowAccountVenueScheduleHandler;
use App\Modules\Venue\Application\UseCases\ShowVenueHandler;
use App\Modules\Venue\Application\UseCases\UpdateVenueScheduleHandler;
use App\Modules\Venue\Presentation\Http\Requests\UpdateVenueScheduleRequest;
use App\Modules\Vk\Domain\Models\VkAccount;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
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
            'currentParticipationRole' => $wizard->primaryParticipationRole($user),
            'participationRoles' => UserParticipationRoleEnum::cases(),
            'genders' => UserGenderEnum::cases(),
            'rolesRequiringProfileDetails' => collect(UserParticipationRoleEnum::cases())
                ->filter(fn (UserParticipationRoleEnum $role): bool => $wizard->roleRequiresBirthDateAndGender($role))
                ->map(fn (UserParticipationRoleEnum $role): string => $role->value)
                ->values()
                ->all(),
        ]);
    }

    public function completeConfirmation(
        CompleteAccountConfirmationWizardRequest $request,
        CompleteAccountConfirmationWizardHandler $completeAccountConfirmationWizard,
    ): RedirectResponse {
        $user = $this->accountCheckForPresentationService->handle($request->user());

        $updatedUser = $completeAccountConfirmationWizard->handle(
            $user,
            $request->role(),
            $request->firstName(),
            $request->lastName(),
            $request->middleName(),
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
    ): RedirectResponse {
        $user = $this->accountCheckForPresentationService->handle($request->user());

        try {
            $contact = $createContactForUser->handle($user, $request->toDTO());
            $startContactVerification->handle($contact);
        } catch (InvalidArgumentException|ContactVerificationCooldownException|LogicException $e) {
            return redirect()
                ->route('account.confirmation')
                ->withInput()
                ->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return redirect()
                ->route('account.confirmation')
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('account.confirmation')
            ->with('status', 'Контакт добавлен. Код подтверждения отправлен.');
    }

    public function startConfirmationContactVerification(
        Contact $contact,
        StartContactVerificationHandler $startContactVerification,
    ): RedirectResponse {
        $user = $this->accountCheckForPresentationService->handle(request()->user());

        abort_unless(
            $user->ownsIdentityContact($contact),
            404,
        );

        try {
            $startContactVerification->handle($contact);
        } catch (ContactVerificationCooldownException $e) {
            return redirect()
                ->route('account.confirmation')
                ->with('info', $e->getMessage())
                ->with('contactVerificationCooldownSeconds', $e->secondsLeft);
        } catch (\Throwable $e) {
            return redirect()
                ->route('account.confirmation')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('account.confirmation')
            ->with('status', 'Код подтверждения отправлен.');
    }

    public function confirmConfirmationContactVerification(
        ConfirmContactVerificationRequest $request,
        Contact $contact,
        ConfirmContactVerificationHandler $confirmContactVerification,
    ): RedirectResponse {
        $user = $this->accountCheckForPresentationService->handle($request->user());

        abort_unless(
            $user->ownsIdentityContact($contact),
            404,
        );

        try {
            $confirmContactVerification->handle($contact, $request->toDTO());
        } catch (ContactVerificationException|LogicException $e) {
            return redirect()
                ->route('account.confirmation')
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('account.confirmation')
            ->with('status', 'Контакт подтвержден.');
    }

    public function settings(): Response
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

        return ThemeResolver::page('account.settings', [
            'user' => $user,
            'privacySettingTypes' => UserPrivacySettingTypeEnum::cases(),
            'privacyVisibilities' => UserPrivacyVisibilityEnum::cases(),
            'privacySettings' => $privacySettings,
            'privacyAllowedUsers' => $privacyAllowedUsers,
        ]);
    }

    public function notifications(
        ListUserNotificationsHandler $listUserNotifications,
        CountNewUserNotificationsHandler $countNewUserNotifications,
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

        return response()->json([
            'notifications' => $listNewUserNotifications->handle($user)
                ->map(fn (UserNotification $notification): array => $presenter->present($notification))
                ->values(),
            'unread_count' => $countNewUserNotifications->handle($user),
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
            'linkedTelegramAccount' => $linkedTelegramAccount,
            'linkedVkAccount' => $linkedVkAccount,
            'telegramBotUsername' => ltrim(trim((string) config('telegram.bot_username')), '@'),
            'vkEnabled' => trim((string) config('vk.app_id')) !== '',
        ]);
    }

    public function storeContact(
        CreateAccountContactRequest $request,
        CreateContactForUserHandler $createContactForUser,
    ): RedirectResponse {
        $user = $this->accountCheckForPresentationService->handle($request->user());

        try {
            $createContactForUser->handle($user, $request->toDTO());
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('account.contacts')
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('account.contacts')
            ->with('status', 'Контакт добавлен.');
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
    ): RedirectResponse {
        $user = $this->accountCheckForPresentationService->handle(request()->user());

        abort_unless(
            $user->ownsIdentityContact($contact),
            404,
        );

        try {
            $startContactVerification->handle($contact);
        } catch (ContactVerificationCooldownException $e) {
            return redirect()
                ->route('account.contacts')
                ->with('info', $e->getMessage())
                ->with('contactVerificationCooldownSeconds', $e->secondsLeft);
        } catch (\Throwable $e) {
            return redirect()
                ->route('account.contacts')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('account.contacts')
            ->with('status', 'Код подтверждения отправлен.');
    }

    public function confirmContactVerification(
        ConfirmContactVerificationRequest $request,
        Contact $contact,
        ConfirmContactVerificationHandler $confirmContactVerification,
    ): RedirectResponse {
        $user = $this->accountCheckForPresentationService->handle($request->user());

        abort_unless(
            $user->ownsIdentityContact($contact),
            404,
        );

        try {
            $confirmContactVerification->handle($contact, $request->toDTO());
        } catch (ContactVerificationException|LogicException $e) {
            return redirect()
                ->route('account.contacts')
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('account.contacts')
            ->with('status', 'Контакт подтвержден.');
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

        return ThemeResolver::page('account.participation-role', [
            'user' => $user,
            'participationRole' => $participationRole,
            'role' => $roleEnum,
            'title' => 'Роль в проекте',
            'playerPositions' => PlayerPositionEnum::cases(),
            'playerBodyTypes' => PlayerBodyTypeEnum::cases(),
            'playerSkills' => PlayerSelfAssessment::SKILLS,
            'objectivePlayerSkills' => PlayerObjectiveAssessment::SKILLS,
        ]);
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
        ]);
    }

    public function updateVenueSchedule(
        UpdateVenueScheduleRequest $request,
        string $alias,
        UpdateVenueScheduleHandler $updateVenueSchedule,
    ): RedirectResponse {
        $user = $this->accountCheckForPresentationService->handle($request->user());

        try {
            $updateVenueSchedule->handle(
                alias: $alias,
                user: $user,
                timezone: $request->timezone(),
                intervalsByDay: $request->intervalsByDay(),
                exceptions: $request->exceptions(),
                operationalStatus: $request->operationalStatus(),
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
}
