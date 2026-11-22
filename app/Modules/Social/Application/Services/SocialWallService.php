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
            && in_array($event->status, [\App\Modules\Event\Domain\Enums\EventStatusEnum::PUBLISHED, \App\Modules\Event\Domain\Enums\EventStatusEnum::COMPLETED], true)) {
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

    public function canCommentOnEvent(Event $event, ?User $user, bool $report = false): bool
    {
        if ($report) {
            return ($event->report_comments_enabled ?? true) && $this->canReactToEvent($event, $user);
        }

        return ! $event->hasEnded()
            && ! in_array($event->status, [\App\Modules\Event\Domain\Enums\EventStatusEnum::CANCELLED], true)
            && ($event->discussion_enabled ?? true)
            && $this->isEventMember($event, $user);
    }

    public function canReactToEvent(Event $event, ?User $user): bool
    {
        return $event->hasEnded() && $this->isEventMember($event, $user, true);
    }

    private function isEventMember(Event $event, ?User $user, bool $confirmedOnly = false): bool
    {
        $user = $user?->canonical();
        if ($user === null || $user->trashed() || $user->isBlocked()) {
            return false;
        }

        $identityIds = $user->identityIds();
        if ($event->organizerActor()->whereIn('user_id', $identityIds)->exists()) {
            return true;
        }
        if (! $user->isConfirmed()) {
            return false;
        }
        $isParticipant = $event->participants()
            ->whereIn('user_id', $identityIds)
            ->where('confirmation_version', $event->participation_confirmation_version)
            ->whereIn('status', $confirmedOnly ? [EventParticipantStatusEnum::CONFIRMED->value] : [
                EventParticipantStatusEnum::CONFIRMED->value,
                EventParticipantStatusEnum::TENTATIVE->value,
            ])->exists();

        return $isParticipant;
    }

    /** @return Collection<int, SocialWallEntry> */
    public function latestForEvent(Event $event, int $limit = 50, ?int $afterId = null, ?int $beforeId = null, bool $report = false): Collection
    {
        $query = SocialWallEntry::query()
            ->where('scope_type', $report ? 'event_report' : 'event')->where('scope_id', $event->id)
            ->whereNull('deleted_at')
            ->with(['actor:id,username,nickname,deleted_at', 'target:id,username,deleted_at']);

        if ($afterId !== null) {
            return $query->where('id', '>', $afterId)->orderBy('id')
                ->limit(max(1, min(100, $limit)))->get();
        }

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
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

    public function postEventComment(Event $event, User $user, string $body, string $requestId, bool $report = false): SocialWallEntry
    {
        if (! $this->canCommentOnEvent($event, $user, $report)) {
            abort(403, 'Комментировать могут организатор и подтверждённые участники мероприятия.');
        }

        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 200) {
            throw new InvalidArgumentException('Сообщение должно содержать от 1 до 200 символов.');
        }
        if (! Str::isUuid($requestId)) {
            throw new InvalidArgumentException('Неверный идентификатор запроса.');
        }

        $user = $user->canonical();

        return $this->limiter->run($user, 'event', $event->id, 'comment', function () use ($event, $user, $body, $requestId, $report): array {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless($this->canCommentOnEvent($lockedEvent, $user, $report), 403);
            $existing = SocialWallEntry::query()
                ->where('scope_type', $report ? 'event_report' : 'event')->where('scope_id', $event->id)
                ->where('actor_user_id', $user->id)
                ->where('client_request_id', $requestId)->first();
            if ($existing !== null) {
                return ['changed' => false, 'value' => $existing->load(['actor', 'target'])];
            }

            $entry = SocialWallEntry::query()->create([
                'scope_type' => $report ? 'event_report' : 'event',
                'scope_id' => $event->id,
                'actor_user_id' => $user->id,
                'kind' => 'comment',
                'body' => $body,
                'client_request_id' => $requestId,
            ]);

            return ['changed' => true, 'value' => $entry->load(['actor', 'target'])];
        });
    }

    public function editOwnEventComment(Event $event, User $user, int $id, string $body, int $version, bool $report = false): SocialWallEntry
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 200) {
            throw new InvalidArgumentException('Сообщение должно содержать от 1 до 200 символов.');
        }

        $user = $user->canonical();
        abort_unless($this->canCommentOnEvent($event, $user, $report), 403);

        return $this->limiter->run($user, 'event', $event->id, 'comment_edit', function () use ($event, $user, $id, $body, $version, $report): array {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless($this->canCommentOnEvent($lockedEvent, $user, $report), 403);
            $entry = SocialWallEntry::query()
                ->where('scope_type', $report ? 'event_report' : 'event')->where('scope_id', $event->id)
                ->where('kind', 'comment')->where('actor_user_id', $user->id)
                ->whereNull('deleted_at')->whereNull('hidden_at')
                ->lockForUpdate()->findOrFail($id);

            $metadata = $entry->metadata ?? [];
            $currentVersion = (int) ($metadata['version'] ?? 1);
            if (trim((string) $entry->body) === $body) {
                return ['changed' => false, 'value' => $entry->load(['actor', 'target'])];
            }

            abort_unless($version === $currentVersion, 409, 'Сообщение уже изменилось. Обнови обсуждение.');

            $history = $metadata['previous_versions'] ?? [];
            $history[] = [
                'version' => $currentVersion,
                'body' => $entry->body,
                'created_at' => $metadata['edited_at'] ?? $entry->created_at?->toIso8601String(),
            ];
            $metadata['previous_versions'] = array_slice($history, -2);
            $metadata['version'] = $currentVersion + 1;
            $metadata['edited_at'] = now()->toIso8601String();

            $entry->forceFill(['body' => $body, 'metadata' => $metadata])->save();

            return ['changed' => true, 'value' => $entry->load(['actor', 'target'])];
        });
    }

    public function deleteOwnEventComment(Event $event, User $user, int $id, bool $report = false): SocialWallEntry
    {
        $user = $user->canonical();

        return $this->limiter->run($user, 'event', $event->id, 'comment_delete', function () use ($event, $user, $id, $report): array {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            $entry = SocialWallEntry::query()
                ->where('scope_type', $report ? 'event_report' : 'event')->where('scope_id', $event->id)
                ->where('kind', 'comment')->where('actor_user_id', $user->id)
                ->lockForUpdate()->findOrFail($id);
            abort_unless($this->canCommentOnEvent($lockedEvent, $user, $report), 403);

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

    /** Current votes are distinct from immutable action history. */
    public function reportFeedback(Event $event, ?User $user): array
    {
        $query = DB::table('event_report_reactions')->where('event_id', $event->id);
        $viewer = $user?->canonical();

        return [
            'likes' => (clone $query)->where('reaction', 'like')->count(),
            'dislikes' => (clone $query)->where('reaction', 'dislike')->count(),
            'reaction' => $viewer ? (clone $query)->where('user_id', $viewer->id)->value('reaction') : null,
            'can_react' => $this->canReactToEvent($event, $viewer),
        ];
    }

    public function setEventReaction(Event $event, User $user, ?string $reaction, ?string $body = null, ?string $requestId = null): array
    {
        abort_unless(in_array($reaction, [null, 'like', 'dislike'], true), 422);
        $body = trim($body ?? '');
        abort_if($body !== '' && ($reaction === null || mb_strlen($body) > 200 || ! Str::isUuid($requestId ?? '')), 422);
        $user = $user->canonical();
        abort_unless($this->canReactToEvent($event, $user), 403);

        return $this->limiter->run($user, 'event_report', $event->id, 'reaction', function () use ($event, $user, $reaction, $body, $requestId): array {
            $locked = Event::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless($this->canReactToEvent($locked, $user), 403);
            $entry = null;
            if ($body !== '') {
                abort_unless($this->canCommentOnEvent($locked, $user, true), 403);
                $entry = SocialWallEntry::query()->where('scope_type', 'event_report')->where('scope_id', $locked->id)
                    ->where('actor_user_id', $user->id)->where('client_request_id', $requestId)->first();
                if ($entry !== null) {
                    return ['changed' => false, 'value' => [...$this->reportFeedback($locked, $user), 'entry' => $entry->load(['actor', 'target'])]];
                }
            }

            $key = ['event_id' => $locked->id, 'user_id' => $user->id];
            $current = DB::table('event_report_reactions')->where($key)->value('reaction');
            if ($current !== $reaction) {
                if ($reaction === null) {
                    DB::table('event_report_reactions')->where($key)->delete();
                } else {
                    DB::table('event_report_reactions')->updateOrInsert($key, ['reaction' => $reaction, 'updated_at' => now()]);
                }
                $this->appendSystem('event_report', $locked->id, $user, 'event_reaction_changed', ['to' => $reaction]);
            }
            if ($body !== '') {
                $entry = SocialWallEntry::query()->create([
                    'scope_type' => 'event_report', 'scope_id' => $locked->id,
                    'actor_user_id' => $user->id, 'kind' => 'comment', 'body' => $body,
                    'client_request_id' => $requestId,
                ])->load(['actor', 'target']);
            }

            return [
                'changed' => $current !== $reaction || $entry !== null,
                'value' => [...$this->reportFeedback($locked, $user), 'entry' => $entry],
            ];
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
        $actorName = in_array($entry->scope_type, ['event','event_report'], true) && filled($actor?->nickname)
            ? $actor->nickname : $actor?->username;
        $name = $actor !== null && ! $actor->trashed() && filled($actorName)
            ? $actorName : 'Удалённый пользователь';
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
            'event_reaction_changed' => match ($status) {
                'like' => 'Пользователю '.$name.' нравится мероприятие.',
                'dislike' => 'Пользователю '.$name.' не нравится мероприятие.',
                default => 'Пользователь '.$name.' убрал оценку.',
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
        $deleted = $entry->deleted_at !== null;
        $editedAt = $entry->kind === 'comment' && ! $hidden && ! $deleted
            ? ($entry->metadata['edited_at'] ?? null) : null;
        $displayDate = $editedAt !== null
            ? \Illuminate\Support\Carbon::parse($editedAt)->setTimezone(config('app.timezone'))
            : $entry->created_at;
        return [
            'id' => $entry->id,
            'kind' => $entry->kind,
            'actor' => $name,
            'text' => $entry->kind === 'comment'
                ? ($deleted ? null : ($hidden ? 'Сообщение скрыто модератором.' : $entry->body))
                : $message,
            'deleted' => $entry->deleted_at !== null,
            'hidden' => $hidden,
            'system' => $entry->kind !== 'comment',
            'rsvp_status' => $entry->kind === 'event_rsvp_changed' ? $status : null,
            'reaction' => $entry->kind === 'event_reaction_changed' && in_array($status, ['like','dislike'], true) ? $status : null,
            'version' => $entry->kind === 'comment' ? (int) ($entry->metadata['version'] ?? 1) : null,
            'date' => $displayDate?->format('d.m.Y H:i'),
            'edited' => $editedAt !== null,
        ];
    }
}
