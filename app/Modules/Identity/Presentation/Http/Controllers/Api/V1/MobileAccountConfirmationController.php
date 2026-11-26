<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Contact\Application\Exceptions\ContactVerificationCooldownException;
use App\Modules\Contact\Application\Exceptions\ContactVerificationDeliveryException;
use App\Modules\Contact\Application\Exceptions\ContactVerificationException;
use App\Modules\Contact\Application\Strategies\EmailContactVerificationStrategy;
use App\Modules\Contact\Application\UseCases\ConfirmContactVerificationHandler;
use App\Modules\Contact\Application\UseCases\CreateContactForUserHandler;
use App\Modules\Contact\Application\UseCases\SetPrimaryContactForUserHandler;
use App\Modules\Contact\Application\UseCases\StartContactVerificationHandler;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Presentation\Http\Requests\ConfirmContactVerificationRequest;
use App\Modules\Contact\Presentation\Http\Requests\CreateAccountContactRequest;
use App\Modules\Identity\Application\Services\AccountConfirmationWizardService;
use App\Modules\Identity\Application\UseCases\CompleteAccountConfirmationWizardHandler;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Exceptions\UserCannotBeChangedException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Presentation\Http\Requests\CompleteAccountConfirmationWizardRequest;
use App\Modules\Identity\Presentation\Http\Resources\Api\V1\OwnUserResource;
use App\Modules\Identity\Presentation\Http\Services\AccountProfileUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

final class MobileAccountConfirmationController extends Controller
{
    public function status(Request $request, AccountConfirmationWizardService $wizard): JsonResponse
    {
        $user = $request->user()->canonical()->refresh();
        $steps = $wizard->steps($user)->map(fn ($step): array => [
            'key' => $step->key,
            'title' => $step->title,
            'description' => $step->description,
            'required' => $step->required,
            'completed' => $step->completed && ($step->key !== 'birth_date' || $user->profile->birth_date->lt(today())),
        ])->values();
        $primary = $wizard->primaryContact($user);

        return response()->json(['data' => [
            'status' => $user->status->value,
            'can_confirm' => $user->status === UserStatusEnum::UNCONFIRMED && $steps->every(fn (array $step): bool => $step['completed']),
            'requirements' => $steps,
            'primary_contact' => $primary === null ? null : [
                'id' => (int) $primary->id,
                'type' => $primary->type->value,
                'is_verified' => $primary->hasBeenVerified(),
            ],
        ]]);
    }

    public function updateProfile(Request $request, AccountProfileUpdater $updater): JsonResponse
    {
        $updater->update($request);

        return response()->json(['data' => $this->ownUser($request->user()->canonical()->refresh(), $request)]);
    }

    public function contacts(Request $request): JsonResponse
    {
        $contacts = $request->user()->canonical()->identityContactsQuery()
            ->with(['verifications' => fn ($query) => $query
                ->where('status', ContactVerificationStatusEnum::PENDING->value)
                ->where('expires_at', '>', now())->latest()])
            ->orderByDesc('is_primary')->orderBy('id')->get();

        return response()->json(['data' => $contacts->map(fn (Contact $contact): array => $this->contactData($contact))->values()]);
    }

    public function createContact(CreateAccountContactRequest $request, CreateContactForUserHandler $handler): JsonResponse
    {
        $data = $request->toDTO();
        if (! in_array($data->type, [ContactTypeEnum::EMAIL, ContactTypeEnum::PHONE], true)
            || $request->filled('replace_contact_id')) {
            throw ValidationException::withMessages(['type' => 'Доступно добавление email и телефона; Telegram и VK требуют официальной авторизации. Замена контакта не поддерживается этим методом.']);
        }

        try {
            $contact = $handler->handle($request->user()->canonical(), $data);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['value' => 'Контакт уже добавлен или недоступен.']);
        }

        return response()->json(['data' => $this->contactData($contact)], 201);
    }

    public function sendCode(Request $request, Contact $contact, StartContactVerificationHandler $handler): JsonResponse
    {
        $this->assertOwned($request, $contact);
        abort_unless($contact->type === ContactTypeEnum::EMAIL, 422, 'Подтверждение кодом доступно только для email.');

        try {
            $verification = $handler->handle($contact);
        } catch (ContactVerificationCooldownException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'seconds_left' => $exception->secondsLeft], 429)
                ->header('Retry-After', (string) $exception->secondsLeft);
        } catch (ContactVerificationDeliveryException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        } catch (LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => [
            'contact_id' => $contact->id,
            'expires_at' => $verification->expires_at?->toIso8601String(),
            'seconds_left' => EmailContactVerificationStrategy::secondsUntilResend($verification),
        ]]);
    }

    public function confirmCode(ConfirmContactVerificationRequest $request, Contact $contact, ConfirmContactVerificationHandler $handler): JsonResponse
    {
        $this->assertOwned($request, $contact);
        abort_unless($contact->type === ContactTypeEnum::EMAIL, 422, 'Подтверждение кодом доступно только для email.');

        try {
            $handler->handle($contact, $request->toDTO());
        } catch (ContactVerificationException|LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->contactData($contact->refresh())]);
    }

    public function setPrimary(Request $request, Contact $contact, SetPrimaryContactForUserHandler $handler): JsonResponse
    {
        $this->assertOwned($request, $contact);

        return response()->json(['data' => $this->contactData($handler->handle($request->user()->canonical(), $contact))]);
    }

    public function confirm(CompleteAccountConfirmationWizardRequest $request, CompleteAccountConfirmationWizardHandler $handler): JsonResponse
    {
        try {
            $user = $handler->handle(
                $request->user()->canonical(),
                $request->firstName(),
                $request->lastName(),
                $request->birthDate(),
                $request->gender(),
            );
        } catch (UserCannotBeChangedException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->ownUser($user, $request)]);
    }

    private function assertOwned(Request $request, Contact $contact): void
    {
        abort_unless($request->user()->canonical()->ownsIdentityContact($contact), 404);
    }

    private function ownUser(User $user, Request $request): array
    {
        return (new OwnUserResource($user->loadMissing('profile.activeAvatar', 'participationRoles')))->toArray($request);
    }

    private function contactData(Contact $contact): array
    {
        $pending = $contact->relationLoaded('verifications')
            ? $contact->verifications->first()
            : $contact->verifications()->where('status', ContactVerificationStatusEnum::PENDING->value)
                ->where('expires_at', '>', now())->latest()->first();

        return [
            'id' => (int) $contact->id,
            'type' => $contact->type->value,
            'value' => $contact->displayValue(),
            'label' => $contact->label,
            'is_primary' => (bool) $contact->is_primary,
            'is_verified' => $contact->hasBeenVerified(),
            'can_verify_by_code' => $contact->type === ContactTypeEnum::EMAIL && ! $contact->hasBeenVerified(),
            'verification_expires_at' => $pending?->expires_at?->toIso8601String(),
            'seconds_left' => EmailContactVerificationStrategy::secondsUntilResend($pending),
        ];
    }
}
