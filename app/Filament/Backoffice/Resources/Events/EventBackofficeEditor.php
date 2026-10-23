<?php

namespace App\Filament\Backoffice\Resources\Events;

use App\Modules\Event\Application\UseCases\CreateEventHandler;
use App\Modules\Event\Application\UseCases\UpdateEventHandler;
use App\Modules\Event\Application\UseCases\CancelEventHandler;
use App\Modules\Event\Application\UseCases\CompleteEventHandler;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class EventBackofficeEditor
{
    public function __construct(
        private readonly CurrentActorResolver $actors,
        private readonly BackofficePermissionManager $permissions,
        private readonly CreateEventHandler $create,
        private readonly UpdateEventHandler $update,
        private readonly CancelEventHandler $cancel,
        private readonly CompleteEventHandler $complete,
    ) {}

    public function authorized(?User $user): bool
    {
        return $user !== null
            && $this->permissions->allows($user, Permission::BACKOFFICE_ACCESS)
            && $this->permissions->allows($user, Permission::BACKOFFICE_EVENT_MANAGE);
    }

    private function actor(): Actor
    {
        $user = auth('web')->user();
        abort_unless($user instanceof User && $this->authorized($user), 403);

        return $this->actors->resolve($user, null)
            ?? throw new InvalidArgumentException('Не удалось определить автора изменений.');
    }

    /** @param array<string, mixed> $data */
    public function createEvent(array $data): Event
    {
        $actor = $this->actor();
        $ownerId = (int) ($data['organizer_user_id'] ?? 0);
        if ($ownerId > 0 && $ownerId !== $actor->user_id) {
            abort_unless($actor->user?->canonical()->system_role === UserSystemRoleEnum::SUPERADMIN, 403);
            $owner = User::query()->findOrFail($ownerId)->canonical();
            abort_unless($owner->isConfirmed() && ! $owner->isBlocked() && ! $owner->trashed(), 422);
            $actor = $this->actors->resolve($owner, null)
                ?? throw new InvalidArgumentException('Не удалось определить организатора.');
        }

        return $this->validation(function () use ($actor, $data): Event {
            return $this->create->handle($actor, $this->payload($data));
        });
    }

    /** @param array<string, mixed> $data */
    public function updateEvent(Event $event, array $data): Event
    {
        $actor = $this->actor();

        return $this->validation(function () use ($event, $actor, $data): Event {
            return $this->update->handle(
                $event->routeIdentifier(), $actor, $this->payload($data), asBackoffice: true,
            );
        });
    }

    public function cancelEvent(Event $event, ?string $reason): Event
    {
        $actor = $this->actor();

        return $this->validation(fn (): Event =>
            $this->cancel->handle($event->routeIdentifier(), $actor, $reason, asBackoffice: true));
    }

    public function completeEvent(Event $event, ?string $description): Event
    {
        $actor = $this->actor();

        return $this->validation(fn (): Event =>
            $this->complete->handle($event->routeIdentifier(), $actor, $description, asBackoffice: true));
    }

    /** @return array<string, mixed> */
    public function fields(Event $event): array
    {
        $event->loadMissing('booking', 'primaryGame');

        return [
            'duration_minutes' => (int) $event->starts_at->diffInMinutes($event->ends_at),
            'booking_scope' => $event->booking?->scope?->value ?? 'whole',
            'game_format' => $event->primaryGame?->format?->value ?? 'streetball_3x3',
            'game_recruitment_mode' => $event->primaryGame?->recruitment_mode?->value ?? 'preformed_teams',
            'game_accepts_applications' => (bool) ($event->primaryGame?->accepts_applications ?? true),
            'side_a_size' => $event->primaryGame?->side_a_size ?? 3,
            'side_b_size' => $event->primaryGame?->side_b_size ?? 3,
            'scoring_type' => $event->primaryGame?->scoring_type?->value ?? 'streetball',
            'timing_mode' => $event->primaryGame?->timing_mode?->value ?? 'whole_game',
            'periods_count' => $event->primaryGame?->periods_count,
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function payload(array $data): array
    {
        $data['duration_minutes'] = (int) ($data['duration_minutes'] ?? 0);
        $data['starts_at'] = (string) ($data['starts_at'] ?? '');
        $data['booking_scope'] = (string) ($data['booking_scope'] ?? 'whole');
        $data['game_accepts_applications'] = (bool) ($data['game_accepts_applications'] ?? true);
        $data['side_a_size'] = (int) ($data['side_a_size'] ?? 3);
        $data['side_b_size'] = (int) ($data['side_b_size'] ?? 3);

        return $data;
    }

    private function validation(callable $action): Event
    {
        try {
            return $action();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['data.title' => $exception->getMessage()]);
        }
    }
}
