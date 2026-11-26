<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Contact\Application\DTO\CreateContactDTO;
use App\Modules\Contact\Application\Services\ContactOwnership;
use App\Modules\Contact\Application\UseCases\CreateContactForUserHandler;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\ValueObjects\ContactValue;
use App\Modules\Identity\Application\Services\TestAccountSequence;
use App\Modules\Identity\Domain\Models\UserConsent;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Application\Services\UsernameSuggestionService;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Exceptions\InvalidIdentityValueException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\ValueObjects\UsernameVO;
use App\Modules\Identity\Domain\ValueObjects\PasswordVO;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminCreateUserHandler
{
    public function __construct(
        private readonly GlobalOperationalPermissionManager $management,
        private readonly UserOperationalPermissionChecker $permissions,
        private readonly UsernameSuggestionService $usernames,
        private readonly CreateUserAccountHandler $accounts,
        private readonly CurrentActorResolver $actors,
    ) {}

    public function canCreate(?User $actor): bool
    {
        return $actor !== null
            && $this->management->mayManage($actor)
            && $this->permissions->allows($actor, Permission::BACKOFFICE_ACCESS)
            && $this->permissions->allows($actor, Permission::BACKOFFICE_USER_CREATE)
            && $this->permissions->allows($actor, Permission::EDIT_USER_PERSONAL_DATA);
    }

    public function canCreateTest(?User $actor): bool
    {
        return app()->environment(['local', 'staging', 'testing'])
            && $this->canCreate($actor)
            && $actor?->canonical()->system_role === UserSystemRoleEnum::SUPERADMIN;
    }

    public function handle(
        User $actor, string $username, string $password, string $passwordConfirmation,
        bool $consentAttested, bool $isTest = false, array $test = [],
    ): User
    {
        if (! $this->canCreate($actor)) {
            throw new AuthorizationException('Нет разрешения на создание пользователя.');
        }

        try {
            $username = UsernameVO::fromString($username)->value;
        } catch (InvalidIdentityValueException $exception) {
            throw ValidationException::withMessages(['username' => $exception->getMessage()]);
        }

        try {
            $password = PasswordVO::fromString($password)->value;
        } catch (InvalidIdentityValueException $exception) {
            throw ValidationException::withMessages(['password' => $exception->getMessage()]);
        }
        if ($password !== $passwordConfirmation) {
            throw ValidationException::withMessages(['password_confirmation' => 'Пароли не совпадают.']);
        }
        if ($isTest && ! $this->canCreateTest($actor)) {
            throw new AuthorizationException('Создание тестовых аккаунтов недоступно.');
        }
        if (! $isTest && ! $consentAttested) {
            throw ValidationException::withMessages(['consent_attested' => 'Подтвердите получение согласия пользователя.']);
        }

        $confirm = $isTest && ($test['confirmed'] ?? false) === true;
        if ($confirm && ! $consentAttested) {
            throw ValidationException::withMessages(['test_confirmed' => 'Для тестового подтверждения отметьте тестовое согласие.']);
        }

        try {
            return DB::transaction(function () use ($actor, $username, $password, $consentAttested, $isTest, $test, $confirm): User {
                $actor = $actor->canonical()->fresh();
                if (! $this->canCreate($actor) || ($isTest && ! $this->canCreateTest($actor))) {
                    throw new AuthorizationException('Нет разрешения на создание пользователя.');
                }

                if (! $this->usernames->isAvailable($username)) {
                    throw ValidationException::withMessages(['username' => 'Этот логин уже занят.']);
                }

                $sequence = app(TestAccountSequence::class);
                $number = $isTest ? $sequence->allocate() : null;
                $contactType = null;
                $contactValue = null;
                $contactVerified = false;
                $verificationSource = null;
                if ($confirm) {
                    $contactType = ContactTypeEnum::tryFrom((string) ($test['contact_type'] ?? ''));
                    $contactVerified = ($test['contact_verified'] ?? false) === true;
                    if (! in_array($contactType, [ContactTypeEnum::EMAIL, ContactTypeEnum::PHONE], true)) {
                        throw ValidationException::withMessages(['test_contact_type' => 'Выберите email или телефон.']);
                    }
                    try {
                        $contactValue = (new ContactValue($contactType, (string) ($test['contact_value'] ?? '')))->value();
                    } catch (\InvalidArgumentException $exception) {
                        throw ValidationException::withMessages(['test_contact_value' => $exception->getMessage()]);
                    }
                    // The generated namespace is reserved for the allocated fixture number.
                    if ($contactType === ContactTypeEnum::EMAIL
                        && preg_match('/^test_[0-9]+@mskba\.ru$/D', $contactValue)
                        && $contactValue !== $sequence->email($number)) {
                        throw ValidationException::withMessages(['test_contact_value' => 'Системный тестовый адрес изменился. Переоткройте форму для нового свободного адреса.']);
                    }
                    if ($contactVerified) {
                        $verificationSource = $contactType === ContactTypeEnum::EMAIL && $contactValue === $sequence->email($number)
                            ? 'filament_test_fixture'
                            : 'filament_test_admin_attested';
                    }
                    $ownership = app(ContactOwnership::class);
                    $ownership->lock($contactType, $contactValue);
                    if (! $sequence->available($contactType, $contactValue)) {
                        throw ValidationException::withMessages(['test_contact_value' => 'Этот контакт уже используется.']);
                    }
                }

                $user = $this->accounts->handle(
                    username: $username,
                    password: $password,
                    registrationChannel: UserRegistrationChannelEnum::OTHER,
                    systemRole: UserSystemRoleEnum::USER,
                    status: UserStatusEnum::UNCONFIRMED,
                    isTemporaryPassword: true,
                );

                if ($isTest) {
                    $user->forceFill([
                        'is_test_account' => true,
                        'test_account_number' => $number,
                        'personal_data_distribution_required_at' => now(),
                    ])->save();
                    if ($consentAttested) {
                        $user->consents()->create([
                            'type' => UserConsent::TYPE_PERSONAL_DATA_PROCESSING,
                            'document_version' => (string) config('legal.personal_data_consent_version'),
                            'accepted_at' => now(),
                            'source' => 'filament_test_fixture',
                            'payload' => ['simulated' => true, 'actor_user_id' => $actor->id],
                        ]);
                    }
                    if ($contactType !== null) {
                        try {
                            $contact = app(CreateContactForUserHandler::class)->handle(
                                $user, new CreateContactDTO($contactType, $contactValue, 'Тестовый контакт'),
                            );
                        } catch (\InvalidArgumentException $exception) {
                            throw ValidationException::withMessages(['test_contact_value' => $exception->getMessage()]);
                        }
                        if ($contactVerified) {
                            // Test-only verification: generated fixture or explicit administrator attestation.
                            $contact->update([
                                'verified_at' => now(),
                                'meta' => [
                                    ...($contact->meta ?? []),
                                    'verification_source' => $verificationSource,
                                    'actor_user_id' => $actor->id,
                                    'otp_verified' => false,
                                ],
                            ]);
                        }
                    }
                    if ($confirm) {
                        // Fixture status, not a real confirmation; no reward event.
                        $user->forceFill(['status' => UserStatusEnum::CONFIRMED])->save();
                    }
                }

                AuditLog::query()->create([
                    'actor_id' => $this->actors->resolve($actor, null)?->id,
                    'auditable_type' => User::class,
                    'auditable_id' => $user->id,
                    'event' => 'admin_user_created',
                    'old_values' => [],
                    'new_values' => ['username' => $username, 'status' => $user->status->value, 'is_test_account' => $isTest, 'test_account_number' => $number],
                    'metadata' => [
                        'source' => 'filament',
                        'actor_user_id' => $actor->id,
                        'consent_attested_by_admin' => ! $isTest && $consentAttested,
                        'test_consent_simulated' => $isTest && $consentAttested,
                        'test_confirmation_simulated' => $confirm,
                        'test_contact_verification_simulated' => $verificationSource === 'filament_test_fixture',
                        'test_contact_verification_attested_by_admin' => $verificationSource === 'filament_test_admin_attested',
                        'test_contact_verification_source' => $verificationSource,
                        'consent_document_version' => (string) config('legal.personal_data_consent_version'),
                        'consent_attested_at' => $consentAttested ? now()->toIso8601String() : null,
                    ],
                ]);

                return $user->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // Check after rollback; a competing writer may have taken the login.
            if (! $this->usernames->isAvailable($username)) {
                throw ValidationException::withMessages(['username' => 'Этот логин уже занят.']);
            }

            if ($isTest && $confirm) {
                throw ValidationException::withMessages(['test_contact_value' => 'Конфликт данных при создании тестового аккаунта. Проверьте контакт и переоткройте форму.']);
            }
            throw $exception;
        }
    }
}
