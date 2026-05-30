<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;

use App\Presentation\Theming\ThemeResolver;
use App\Modules\Identity\Application\Services\AccountCheckForPresentationService;


use App\Modules\Contract\Application\UseCases\ListAccountContracts;
use App\Modules\Contract\Application\UseCases\ShowAccountContract;
use App\Modules\Venue\Application\UseCases\ListAccountVenues;
use App\Modules\Venue\Application\UseCases\ShowVenue;

use Illuminate\View\View;
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

    public function contracts(ListAccountContracts $listAccountContracts): Response
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

    public function contract(string $number, ShowAccountContract $showAccountContract): Response
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

    public function venues(ListAccountVenues $listAccountVenues): Response
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

    public function showVenue(string $alias, ShowVenue $showVenue): Response
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
}
