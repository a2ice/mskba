<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Application\UseCases\ListAccountContractsHandler;
use App\Modules\Contract\Application\UseCases\ShowAccountContractHandler;
use App\Modules\Identity\Application\Services\AccountCheckForPresentationService;
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

        $data = ['user' => $user];

        return ThemeResolver::page('account.index', $data);
    }

    public function settings(): Response
    {
        return ThemeResolver::page('account.settings');
    }

    public function contacts(): Response
    {
        return ThemeResolver::page('account.contacts');
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
