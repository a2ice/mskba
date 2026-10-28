<?php

namespace App\Modules\Contract\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Application\Services\AccountContractsWorkspace;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Application\Services\AccountCheckForPresentationService;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class AccountContractsWorkspaceController extends Controller
{
    public function index(
        Request $request,
        AccountCheckForPresentationService $check,
        AccountContractsWorkspace $workspace,
    ): Response {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(array_column(ContractMembershipScopeTypeEnum::cases(), 'value'))],
            'status' => ['nullable', Rule::in(array_column(ContractStatusEnum::cases(), 'value'))],
        ]);
        $user = $check->handle($request->user());

        return ThemeResolver::page('account.contracts', [
            'contracts' => $workspace->contracts($user, $filters),
            'filters' => $filters,
            'contractTypes' => ContractMembershipScopeTypeEnum::cases(),
            'contractStatuses' => ContractStatusEnum::cases(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function venues(
        Request $request,
        AccountCheckForPresentationService $check,
        AccountContractsWorkspace $workspace,
    ): Response {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'none'])],
            'specialization' => ['nullable', Rule::in(array_column(VenueTypeEnum::cases(), 'value'))],
        ]);
        $user = $check->handle($request->user());

        return ThemeResolver::page('account.venues', [
            'venues' => $workspace->venues($user, $filters),
            'filters' => $filters,
            'specializations' => VenueTypeEnum::cases(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
