<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Global, explicitly delegated Filament oversight. Venue memberships still
 * control ordinary portal actions; backoffice access must never be implicit.
 */
final readonly class VenueBookingBackofficeAccess
{
    public function __construct(private BackofficePermissionManager $permissions) {}

    public function canView(?User $user): bool
    {
        return $user !== null
            && $this->permissions->allows($user, Permission::BACKOFFICE_ACCESS)
            && $this->permissions->allows($user, Permission::BACKOFFICE_VENUE_BOOKINGS_VIEW);
    }

    public function canManage(?User $user): bool
    {
        return $this->canView($user)
            && $this->permissions->allows($user, Permission::BACKOFFICE_VENUE_BOOKINGS_MANAGE);
    }

    public function assertView(): void
    {
        abort_unless($this->canView(auth('web')->user()), 403);
    }

    public function actor(bool $managing = true): Actor
    {
        $user = auth('web')->user();
        abort_unless($managing ? $this->canManage($user) : $this->canView($user), 403);

        $actor = app(CurrentActorResolver::class)->resolve($user, null);
        abort_if($actor === null, 403);

        return $actor;
    }

    public function logConversationView(Model $record): void
    {
        $actor = $this->actor(false);
        AuditLog::query()->create([
            'actor_id' => $actor->id,
            'auditable_type' => $record::class,
            'auditable_id' => $record->getKey(),
            'event' => 'rental_support_conversation_viewed',
            'old_values' => [],
            'new_values' => [],
            'metadata' => ['interface' => 'filament', 'venue_id' => $record->venue_id],
        ]);
    }
}
