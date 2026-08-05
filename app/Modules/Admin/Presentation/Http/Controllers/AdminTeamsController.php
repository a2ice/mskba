<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class AdminTeamsController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:140'],
            'status' => ['nullable', Rule::enum(TeamStatusEnum::class)],
            'temporary' => ['nullable', Rule::in(['yes', 'no'])],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        return ThemeResolver::page('admin.teams', [
            'teams' => Team::query()
                ->withTrashed()
                ->with(['createdByActor.user.profile', 'sportProfiles'])
                ->withCount(['memberships as active_memberships_count' => fn ($query) => $query
                    ->whereHas('contract', fn ($contract) => $contract->where('status', ContractStatusEnum::ACTIVE->value))])
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->whereLike('name', "%{$search}%")
                        ->orWhereLike('description', "%{$search}%");
                }))
                ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
                ->when(($filters['temporary'] ?? null) === 'yes', fn ($query) => $query->whereNotNull('temporary_for_event_id'))
                ->when(($filters['temporary'] ?? null) === 'no', fn ($query) => $query->whereNull('temporary_for_event_id'))
                ->orderByDesc('id')
                ->paginate(30)
                ->withQueryString(),
            'statuses' => TeamStatusEnum::cases(),
            'filters' => $filters,
        ]);
    }

    public function show(string $team): Response
    {
        $item = Team::withTrashed()
            ->whereRouteIdentifier($team)
            ->with([
                'createdByActor.user.profile',
                'temporaryForEvent',
                'sportProfiles',
                'memberships.contract.permissions',
                'memberships.user.profile',
            ])
            ->firstOrFail();

        return ThemeResolver::page('admin.team-show', [
            'team' => $item,
            'activeMemberships' => $item->memberships
                ->filter(fn ($membership) => $membership->contract?->status === ContractStatusEnum::ACTIVE)
                ->values(),
        ]);
    }
}
