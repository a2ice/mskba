<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contact\Application\Exceptions\ContactVerificationCooldownException;
use App\Modules\Contact\Application\UseCases\CreateContactForUserHandler;
use App\Modules\Contact\Application\UseCases\StartContactVerificationHandler;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Presentation\Http\Requests\CreateAccountContactRequest;
use App\Modules\Contract\Application\UseCases\ListAccountContractsHandler;
use App\Modules\Contract\Application\UseCases\ShowAccountContractHandler;
use App\Modules\Identity\Application\Services\AccountCheckForPresentationService;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Venue\Application\UseCases\CreateAccountVenueHandler;
use App\Modules\Venue\Application\UseCases\ListAccountVenuesHandler;
use App\Modules\Venue\Application\UseCases\ShowVenueHandler;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Presentation\Http\Requests\CreateVenueRequest;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountCheckForPresentationService $accountCheckForPresentationService,
    ) {}

    public function index(): Response
    {
        try {
            $user = $this->accountCheckForPresentationService->handle(request()->user());
        } catch (\Exception $e) {
            return ThemeResolver::page('account.index', ['error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        $user->load('profile', 'participationRoles');

        if ($user->participationRoles) {
            $participationRoleLabels = $user->participationRoles
                ->map(fn ($participationRole) => $participationRole->role->label())
                ->join(', ');
            $user->participation_role_labels = $participationRoleLabels;
        }

        $user->email = $user->primaryEmail()?->value;

        $data = ['user' => $user];

        return ThemeResolver::page('account.index', $data);
    }

    public function settings(): Response
    {
        return ThemeResolver::page('account.settings');
    }

    public function contacts(): Response
    {
        try {
            $user = $this->accountCheckForPresentationService->handle(request()->user());
        } catch (\Exception $e) {
            return ThemeResolver::page('account.contacts', ['error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        $user->load(['contacts' => fn ($query) => $query
            ->orderByDesc('is_primary')
            ->orderBy('type')
            ->orderBy('created_at')]);

        return ThemeResolver::page('account.contacts', [
            'user' => $user,
            'contactTypes' => ContactTypeEnum::cases(),
        ]);
    }

    public function storeContact(
        CreateAccountContactRequest $request,
        CreateContactForUserHandler $createContactForUser,
    ): RedirectResponse {
        $user = $this->accountCheckForPresentationService->handle($request->user());

        $createContactForUser->handle($user, $request->toDTO());

        return redirect()
            ->route('account.contacts')
            ->with('status', 'Контакт добавлен.');
    }

    public function startContactVerification(
        Contact $contact,
        StartContactVerificationHandler $startContactVerification,
    ): RedirectResponse {
        $user = $this->accountCheckForPresentationService->handle(request()->user());

        abort_unless(
            $contact->contactable_type === 'user' && (int) $contact->contactable_id === (int) $user->id,
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

    public function participationRole(string $role): Response
    {
        $roleEnum = UserParticipationRoleEnum::tryFrom($role);

        abort_if($roleEnum === null, 404);

        try {
            $user = $this->accountCheckForPresentationService->handle(request()->user());
        } catch (\Exception $e) {
            return ThemeResolver::page('account.participation-role', ['error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        $user->loadMissing('playerProfile');

        $participationRole = $user->participationRoles()
            ->where('role', $roleEnum->value)
            ->first();

        abort_if($participationRole === null, 404);

        return ThemeResolver::page('account.participation-role', [
            'user' => $user,
            'participationRole' => $participationRole,
            'role' => $roleEnum,
            'title' => $roleEnum->label(),
        ]);
    }

    public function contracts(ListAccountContractsHandler $listAccountContracts): Response
    {
        try {
            $user = $this->accountCheckForPresentationService->handle(request()->user());
        } catch (\Exception $e) {
            return ThemeResolver::page('account.contracts', ['error' => [
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
            return ThemeResolver::page('account.contract', ['error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        try {
            $contract = $showAccountContract->handle($number, $user);
        } catch (\Exception $e) {
            return ThemeResolver::page('account.contract', ['error' => [
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
            return ThemeResolver::page('account.venues', ['error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        return ThemeResolver::page('account.venues', [
            'venues' => $listAccountVenues->handle($user),
        ]);
    }

    public function createVenue(): Response
    {
        try {
            $user = $this->accountCheckForPresentationService->handle(request()->user());
        } catch (\Exception $e) {
            return ThemeResolver::page('account.venue-create', ['error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        abort_unless($user->can('add_venue'), 403);

        return ThemeResolver::page('account.venue-create', [
            'types' => VenueTypeEnum::cases(),
        ]);
    }

    public function storeVenue(CreateVenueRequest $request, CreateAccountVenueHandler $createAccountVenue): RedirectResponse
    {
        $user = $this->accountCheckForPresentationService->handle($request->user());
        $venue = $createAccountVenue->handle($user, $request->validated());

        return redirect()
            ->route('account.venues.show', $venue->alias)
            ->with('status', 'Площадка добавлена и ожидает подтверждения.');
    }

    public function showVenue(string $alias, ShowVenueHandler $showVenue): Response
    {
        try {
            $user = $this->accountCheckForPresentationService->handle(request()->user());
        } catch (\Exception $e) {
            return ThemeResolver::page('account.venue', ['error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        try {
            $venue = $showVenue->handle($alias, $user);
        } catch (\Exception $e) {
            return ThemeResolver::page('account.venue', ['error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        return ThemeResolver::page('account.venue', [
            'venue' => $venue,
        ]);
    }

    public function editVenue(string $alias): Response
    {
        return ThemeResolver::page('account.venue', ['error' => [
            'message' => 'Редактирование площадки будет реализовано отдельно.',
            'code' => 501,
        ]]);
    }
}
