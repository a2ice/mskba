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
use Filament\Notifications\Notification;
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
            $payload = $this->payload($data);
            // Preserve legacy behavior for direct callers that do not send
            // the checkbox. The Filament creation form always sends it.
            if (array_key_exists('organizer_participates', $data)) {
                $payload['organizer_participates'] = (bool) $data['organizer_participates'];
            }
            if ($payload['organizer_participates'] ?? false) {
                $roles = $data['participation_roles'] ?? null;
                $allowed = array_keys(EventResource::participationRoleOptions());
                if (! is_array($roles) || $roles === []
                    || collect($roles)->contains(fn ($role): bool =>
                        ! is_string($role) || ! in_array($role, $allowed, true))
                    || count($roles) !== count(array_unique($roles))) {
                    throw ValidationException::withMessages([
                        'data.participation_roles' => 'Выберите хотя бы одну допустимую роль участия.',
                    ]);
                }
                $payload['participation_roles'] = $roles;
            }

            return $this->create->handle($actor, $payload);
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
        $event->loadMissing('booking', 'sourceBooking', 'primaryGame', 'organizerActor.user');

        return [
            'booking_record_id' => $event->booking?->id,
            'booking_status_label' => $event->booking?->status?->label() ?? 'Отсутствует',
            'source_booking_id' => $event->booking_id,
            'organizer_label' => $event->organizerActor?->user?->username ?? 'Системный актор',
            'event_date' => $event->starts_at->setTimezone('Europe/Moscow')->format('Y-m-d'),
            'event_time' => $event->starts_at->setTimezone('Europe/Moscow')->format('H:i'),
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
        if (array_key_exists('event_date', $data) || array_key_exists('event_time', $data)) {
            $date = substr(trim((string) ($data['event_date'] ?? '')), 0, 10);
            $time = trim((string) ($data['event_time'] ?? ''));
            if ($date === '') {
                throw ValidationException::withMessages(['data.event_date' => 'Укажите дату мероприятия.']);
            }
            if ($time === '') {
                throw ValidationException::withMessages(['data.event_time' => 'Укажите время начала мероприятия.']);
            }
            // A legacy Filament TimePicker may dehydrate into a full date-time
            // string instead of HH:mm. Never truncate its FIRST five bytes:
            // that would use the date as a time and silently discard edits.
            if (preg_match('/^(?:\\d{4}-\\d{2}-\\d{2}[ T])?((?:[01]\\d|2[0-3]):[0-5]\\d)(?::[0-5]\\d)?$/D', $time, $matches) !== 1) {
                throw ValidationException::withMessages([
                    'data.event_time' => 'Укажите корректное время в формате ЧЧ:ММ.',
                ]);
            }
            $data['starts_at'] = $date.' '.$matches[1];
        } else {
            // Keep supporting existing application-service callers.
            $data['starts_at'] = (string) ($data['starts_at'] ?? '');
        }
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
            $message = $exception->getMessage();
            $field = match (true) {
                str_contains($message, 'половин'),
                str_contains($message, 'зал') => 'data.venue_court_id',
                str_contains($message, 'длительност'),
                str_contains($message, 'в течение суток') => 'data.duration_minutes',
                str_contains($message, 'время'),
                str_contains($message, 'интервал'),
                str_contains($message, 'занято'),
                str_contains($message, 'час') => 'data.event_time',
                str_contains($message, 'площадк') => 'data.venue_id',
                str_contains($message, 'участник') => 'data.max_participants',
                default => 'data.title',
            };

            // The user may be on a different Filament tab from the invalid
            // field. Show the domain reason globally as well as inline.
            Notification::make()
                ->danger()
                ->title('Не удалось сохранить мероприятие')
                ->body($message)
                ->send();

            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
