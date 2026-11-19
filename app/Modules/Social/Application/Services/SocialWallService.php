<?php

namespace App\Modules\Social\Application\Services;

use App\Modules\Event\Application\Services\EventIdentityParticipationService;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Social\Domain\Models\SocialWallEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SocialWallService
{
    public function __construct(
        private readonly SocialActionLimiter $limiter,
        private readonly EventIdentityParticipationService $participation,
    ) {}

    public function canViewEvent(Event $event, ?User $user): bool
    {
        // A published public event is discoverable, including its discussion:
        // visitors need to understand who is attending before deciding to join.
        // Writing remains restricted to confirmed participants and organizers.
        if ($event->visibility === EventVisibilityEnum::PUBLIC
            && $event->status === \App\Modules\Event\Domain\Enums\EventStatusEnum::PUBLISHED) {
            return true;
        }

        if ($user === null || $user->canonical()->trashed() || $user->canonical()->isBlocked()) {
            return false;
        }

        // Private events keep their existing participant/organizer visibility.
        $identityIds = $user->canonical()->identityIds();

        return $event->participants()
                ->whereIn('user_id', $identityIds)
                ->whereIn('status', [
                    EventParticipantStatusEnum::CONFIRMED->value,
                    EventParticipantStatusEnum::TENTATIVE->value,
                ])->exists()
            || $event->organizerActor()->whereIn('user_id', $identityIds)->exists();
    }

    public function canCommentOnEvent(Event $event, ?User $user): bool
    {
        if ($user === null || ! $user->canonical()->isConfirmed() || $user->canonical()->isBlocked()) {
            return false;
        }

        $identityIds = $user->canonical()->identityIds();
        $isOrganizer = $event->organizerActor()->whereIn('user_id', $identityIds)->exists();
        $isParticipant = $event->participants()
            ->whereIn('user_id', $identityIds)
            ->where('confirmation_version', $event->participation_confirmation_version)
            ->whereIn('status', [
                EventParticipantStatusEnum::CONFIRMED->value,
                EventParticipantStatusEnum::TENTATIVE->value,
            ])->exists();

        return $isOrganizer || $isParticipant;
    }

    /** @return Collection<int, SocialWallEntry> */
    public function latestForEvent(Event $event, int $limit = 50, ?int $afterId = null): Collection
    {
        $query = SocialWallEntry::query()
            ->where('scope_type', 'event')->where('scope_id', $event->id)
            ->with(['actor:id,username,deleted_at', 'target:id,username,deleted_at']);

        if ($afterId !== null) {
            return $query->where('id', '>', $afterId)->orderBy('id')
                ->limit(max(1, min(100, $limit)))->get();
        }

        return $query->orderByDesc('id')->limit(max(1, min(100, $limit)))
            ->get()->reverse()->values();
    }

    public function appendSystem(
        string $scope,
        int $scopeId,
        ?User $actor,
        string $kind,
        array $metadata = [],
        ?User $target = null,
    ): SocialWallEntry {
        return SocialWallEntry::query()->create([
            'scope_type' => $scope,
            'scope_id' => $scopeId,
            'actor_user_id' => $actor?->canonical()->id,
            'target_user_id' => $target?->canonical()->id,
            'kind' => $kind,
            'metadata' => $metadata,
        ]);
    }

    public function postEventComment(Event $event, User $user, string $body, string $requestId): SocialWallEntry
    {
        if (! $this->canCommentOnEvent($event, $user)) {
            abort(403, 'Комментировать могут только подтверждённые участники мероприятия.');
        }

        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 200) {
            throw new InvalidArgumentException('Сообщение должно содержать от 1 до 200 символов.');
        }
        if (! Str::isUuid($requestId)) {
            throw new InvalidArgumentException('Неверный идентификатор запроса.');
        }

        $user = $user->canonical();

        return $this->limiter->run($user, 'event', $event->id, 'comment', function () use ($event, $user, $body, $requestId): array {
            $existing = SocialWallEntry::query()
                ->where('scope_type', 'event')->where('scope_id', $event->id)
                ->where('actor_user_id', $user->id)
                ->where('client_request_id', $requestId)->first();
            if ($existing !== null) {
                return ['changed' => false, 'value' => $existing->load(['actor', 'target'])];
            }

            $entry = SocialWallEntry::query()->create([
                'scope_type' => 'event',
                'scope_id' => $event->id,
                'actor_user_id' => $user->id,
                'kind' => 'comment',
                'body' => $body,
                'client_request_id' => $requestId,
            ]);

            return ['changed' => true, 'value' => $entry->load(['actor', 'target'])];
        });
    }

    public function deleteOwnEventComment(Event $event, User $user, int $id): SocialWallEntry
    {
        $user = $user->canonical();

        return $this->limiter->run($user, 'event', $event->id, 'comment_delete', function () use ($event, $user, $id): array {
            $entry = SocialWallEntry::query()
                ->where('scope_type', 'event')->where('scope_id', $event->id)
                ->where('kind', 'comment')->where('actor_user_id', $user->id)
                ->lockForUpdate()->findOrFail($id);

            if ($entry->deleted_at !== null) {
                return ['changed' => false, 'value' => $entry->load(['actor', 'target'])];
            }

            $entry->forceFill([
                'deleted_at' => now(),
                'deleted_by_user_id' => $user->id,
            ])->save();

            return ['changed' => true, 'value' => $entry->load(['actor', 'target'])];
        });
    }

    public function eventCounts(Event $event): array
    {
        $known = $this->participation->publicStatusCounts($event);
        $left = [];
        $participants = $event->participants()
            ->with('user:id,canonical_user_id,username')
            ->where('confirmation_version', $event->participation_confirmation_version)
            ->where('status', EventParticipantStatusEnum::LEFT->value)->get();
        foreach ($participants as $participant) {
            if ($participant->user !== null && $participant->hasPlayerRole()
                && ! ($participant->role === EventParticipantRoleEnum::ORGANIZER
                    && $participant->participation_roles === [])) {
                $left[$participant->user->canonical()->id] = true;
            }
        }

        $otherStatuses = $event->participants()
            ->with('user:id,canonical_user_id,username')
            ->where('confirmation_version', $event->participation_confirmation_version)
            ->whereIn('status', [
                EventParticipantStatusEnum::CONFIRMED->value,
                EventParticipantStatusEnum::TENTATIVE->value,
            ])->get();
        foreach ($otherStatuses as $participant) {
            if ($participant->user !== null) {
                unset($left[$participant->user->canonical()->id]);
            }
        }

        return [...$known, 'left' => count($left)];
    }

    public function present(SocialWallEntry $entry): array
    {
        $actor = $entry->actor;
        $name = $actor !== null && ! $actor->trashed() && filled($actor->username)
            ? $actor->username : 'Удалённый пользователь';
        $target = $entry->target;
        $targetName = $target !== null && ! $target->trashed() && filled($target->username)
            ? $target->username : 'Удалённый пользователь';
        $status = (string) ($entry->metadata['to'] ?? '');
        $message = match ($entry->kind) {
            'event_rsvp_changed' => $name.' '.match ($status) {
                'confirmed' => 'пойдёт на мероприятие.',
                'tentative' => 'пока думает, идти ли на мероприятие.',
                'left' => 'не пойдёт на мероприятие.',
                default => 'изменил ответ на участие.',
            },
            'group_admin_nominated' => $name.' подал заявку на администратора группы.',
            'group_admin_vote_cast' => $name.' подтвердил назначение '.$targetName.' администратором группы.',
            'group_admin_vote_changed' => $name.' изменил выбор и поддержал назначение '.$targetName.' администратором группы.',
            'group_admin_vote_retracted' => $name.' отозвал голос за администратора группы.',
            'group_admin_elected' => $targetName.' избран администратором группы.',
            'group_admin_replaced' => 'Администратором группы стал '.$targetName.'.',
            'group_admin_staff_override' => 'Администратор MSKBA изменил руководство группы.',
            'group_election_cancelled' => 'Выборы отменены из-за изменения состава участников.',
            'group_election_closed' => 'Выборы завершены без победителя.',
            'group_title_changed' => $name.' изменил название группы.',
            'group_assembly_started' => $name.' начал согласование предстоящей игры.',
            'group_event_created' => $name.' создал мероприятие по итогам согласования.',
            default => $name.' выполнил действие.',
        };
        $hidden = $entry->hidden_at !== null;
        return [
            'id' => $entry->id,
            'kind' => $entry->kind,
            'actor' => $name,
            'text' => $entry->kind === 'comment'
                ? ($hidden ? 'Сообщение скрыто модератором.' : $entry->body)
                : $message,
            'deleted' => $entry->deleted_at !== null,
            'hidden' => $hidden,
            'system' => $entry->kind !== 'comment',
            'date' => $entry->created_at?->format('d.m.Y H:i'),
        ];
    }
}
