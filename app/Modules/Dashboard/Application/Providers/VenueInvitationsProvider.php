<?php

namespace App\Modules\Dashboard\Application\Providers;

use App\Modules\Dashboard\Application\Contracts\DashboardDataProvider;
use App\Modules\Dashboard\Application\Data\DashboardWidgetData;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Invitation\Application\Services\AccountInvitationAccess;
use App\Modules\Invitation\Domain\Models\AccountInvitation;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;

final readonly class VenueInvitationsProvider implements DashboardDataProvider
{
    public function __construct(
        private AccountInvitationAccess $access,
        private VenueMembershipAccess $memberships,
    ) {}

    /** @return list<int> */
    private function venueIds(User $user): array
    {
        if (! $this->access->canViewAny($user)) {
            return [];
        }

        // This is an operational widget, not a global backoffice report.
        // Even superadmins need an actual venue scope to see it.
        return Venue::query()->whereKey($this->memberships->allowedVenueIdsFor($user, VenuePermissionEnum::MANAGE_MEMBERSHIPS))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public function isAvailable(User $user, array $settings): bool
    {
        return $this->venueIds($user->canonical()) !== [];
    }

    public function canInvalidateFor(User $user, AccountInvitation $record): bool
    {
        return $record->scope_type === 'venue'
            && in_array((int) $record->scope_id, $this->venueIds($user->canonical()), true);
    }

    public function getData(User $user, array $settings): DashboardWidgetData
    {
        $user = $user->canonical();
        $venueIds = $this->venueIds($user);
        abort_if($venueIds === [], 404);
        $limit = max(1, min(10, is_numeric($settings['limit'] ?? null) ? (int) $settings['limit'] : 5));
        // Keep the dashboard narrower than, never broader than, the canonical invitation ACL.
        $query = $this->access->constrain(AccountInvitation::query(), $user)
            ->where('scope_type', 'venue')->whereIn('scope_id', $venueIds);
        $now = now();
        $pending = (clone $query)->where('status', 'pending');
        $expired = (clone $pending)->whereNull('accepted_user_id')->where('expires_at', '<', $now)->count();
        $url = route('filament.backoffice.resources.account-invitations.index', [], false);
        $summary = [
            'pending' => $pending->count() - $expired,
            'expired' => $expired,
            'completed' => (clone $query)->where('status', 'completed')->count(),
            'revoked' => (clone $query)->where('status', 'revoked')->count(),
            'url' => $url,
        ];
        $records = (clone $query)->orderByDesc('updated_at')->orderByDesc('id')->limit($limit)->get([
            'id', 'scope_id', 'status', 'progress', 'accepted_user_id', 'sent_at',
            'viewed_at', 'interested_at', 'expires_at', 'updated_at',
        ]);
        $names = Venue::query()->whereKey($records->pluck('scope_id')->unique()->all())->pluck('name', 'id');
        $items = $records->map(static fn (AccountInvitation $record): array => [
            'id' => (int) $record->id,
            'title' => $names->get($record->scope_id, 'Площадка'),
            'invitation_status' => $record->invitationStatusLabel(),
            'progress_label' => $record->progressLabel(),
            'status' => $record->effectiveStatus(),
            'deadline_at' => $record->effectiveStatus() === 'pending' && $record->accepted_user_id === null
                ? $record->expires_at->copy()->utc()->toIso8601String() : null,
        ])->all();

        // No contact values, custom invitation titles, accepted identities or token material.
        // Link only to the independently authorized existing backoffice index.
        return new DashboardWidgetData((clone $query)->count(), $items, $summary);
    }
}
