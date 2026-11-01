<?php

namespace App\Modules\Contract\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class EntityContractController extends Controller
{
    private function authorizeManage(Request $request, ContractMembership $membership, EntityContractManager $contracts): void
    {
        $permission = $contracts->managePermission($membership->scope_type);
        abort_unless($permission !== null && $contracts->allows(
            $request->user()->canonical(), $membership->scope_type, (int) $membership->scope_id, $permission,
        ), 403);
    }

    public function show(Request $request, ContractMembership $membership, EntityContractManager $contracts): Response
    {
        $this->authorizeManage($request, $membership, $contracts);
        $scope = $membership->scope_type;
        $scopeId = (int) $membership->scope_id;
        $effective = $contracts->effectivePermissions($request->user(), $scope, $scopeId);
        $options = array_values(array_filter(
            $contracts->permissionCatalog($scope),
            static fn (\BackedEnum $permission): bool => in_array($permission->value, $effective, true),
        ));
        $memberships = ContractMembership::query()
            ->where('scope_type', $scope->value)
            ->where('scope_id', $scopeId)
            ->with(['user', 'contract.permissions'])
            ->whereHas('contract')
            ->orderByDesc('id')->paginate(30);
        $entityClass = $scope->modelClass();
        $entity = $entityClass === null ? null : $entityClass::query()->find($scopeId);

        return ThemeResolver::page('account.contract-management', [
            'membership' => $membership,
            'scopeLabel' => $scope->label(),
            'entityName' => $entity === null ? $scope->label() : $entity->{$scope->titleAttribute()},
            'permissions' => $options,
            'memberships' => $memberships,
            'canTransfer' => $scope !== \App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum::VENUE
                && $contracts->isOwner($request->user(), $scope, $scopeId),
        ]);
    }

    public function invite(Request $request, ContractMembership $membership, EntityContractManager $contracts): RedirectResponse
    {
        $this->authorizeManage($request, $membership, $contracts);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:150'],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'permissions' => ['required', 'array', 'min:1'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'permissions.*' => ['required', 'string', 'distinct'],
        ]);
        $target = User::query()->where('username', trim($data['username']))->first();
        if ($target === null) {
            return back()->withErrors(['username' => 'Пользователь не найден.'])->withInput();
        }
        $contracts->invite($membership->scope_type, (int) $membership->scope_id,
            $request->user(), $target, $data['name'], $data['permissions'],
            $data['expires_at'] ?? null, $data['notes'] ?? null);
        return back()->with('status', 'Приглашение отправлено.');
    }

    public function transfer(Request $request, ContractMembership $membership, EntityContractManager $contracts): RedirectResponse
    {
        $this->authorizeManage($request, $membership, $contracts);
        $data = $request->validate(['username' => ['required', 'string', 'max:150']]);
        $target = User::query()->where('username', trim($data['username']))->first();
        if ($target === null) {
            return back()->withErrors(['username' => 'Пользователь не найден.']);
        }
        $contracts->transferOwner($membership->scope_type, (int) $membership->scope_id, $request->user(), $target);
        return back()->with('status', 'Предложение о передаче владения отправлено.');
    }

    public function respond(Request $request, ContractMembership $membership, EntityContractManager $contracts): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:accept,decline']]);
        $contracts->respond($membership, $request->user(), $data['decision'] === 'accept');
        return redirect()->route('account.contracts')->with('status', $data['decision'] === 'accept'
            ? 'Контракт принят.' : 'Приглашение отклонено.');
    }

    public function revoke(
        Request $request, ContractMembership $membership, ContractMembership $target, EntityContractManager $contracts,
    ): RedirectResponse {
        $this->authorizeManage($request, $membership, $contracts);
        abort_unless($target->scope_type === $membership->scope_type
            && (int) $target->scope_id === (int) $membership->scope_id, 404);
        $contracts->revoke($target, $request->user());
        return back()->with('status', 'Контракт отозван.');
    }
}
