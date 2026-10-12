<?php

namespace App\Modules\Notification\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Team\Domain\Models\TeamJoinRequest;
use Illuminate\Support\Collection;

final class BuildTeamRequestTimelineHandler
{
    /** @return Collection<int, array<string, mixed>> */
    public function handle(TeamJoinRequest $request, User $viewer): Collection
    {
        $identityIds = $viewer->canonical()->identityIds();
        $notifications = UserNotification::query()
            ->whereIn('user_id', $identityIds)
            ->where('payload->join_request_id', $request->id)
            ->orderBy('created_at')->orderBy('id')->get();

        // The application event is the first visible system message for BOTH
        // parties. Team managers have a stored "submitted" notification; the
        // applicant may only have the application itself as the event source.
        $submitted = $notifications->first(fn (UserNotification $notification): bool =>
            ($notification->payload['source'] ?? '') === 'team.join_request.submitted');

        $initial = [
            'key' => 'application-'.$request->id,
            'kind' => 'system',
            'heading' => $submitted?->title ?? 'Заявка отправлена',
            'body' => $submitted?->body ?? 'Заявка на вступление в команду «'.$request->team->name.'» отправлена.',
            'created_at' => $request->created_at,
            'sender' => null,
            'mine' => false,
            'notification' => $submitted,
        ];

        $rest = $notifications
            ->reject(fn (UserNotification $item): bool => $submitted !== null && $item->id === $submitted->id)
            ->map(fn (UserNotification $item): array => [
                'key' => 'notification-'.$item->id,
                'kind' => 'system',
                'heading' => $item->title,
                'body' => $item->body,
                'created_at' => $item->created_at,
                'sender' => null,
                'mine' => false,
                'notification' => $item,
            ]);

        $request->loadMissing('messages.sender.profile');
        $messages = $request->messages->map(function ($message) use ($identityIds): array {
            $sender = $message->sender?->canonical();
            return [
                'key' => 'message-'.$message->id,
                'kind' => 'person',
                'heading' => '',
                'body' => $message->body,
                'created_at' => $message->created_at,
                'sender' => $sender,
                'mine' => $sender !== null && in_array((int) $sender->id, $identityIds, true),
                'notification' => null,
            ];
        });

        $rest = $rest->concat($messages);
        if ($request->reviewed_at !== null) {
            // For reviewers a decision notification is not sent to themselves,
            // so show the decision in their timeline as a system event.
            $hasReviewEvent = $rest->contains(fn (array $item): bool =>
                $item['kind'] === 'system'
                && str_starts_with((string) ($item['notification']?->payload['source'] ?? ''), 'team.join_request.')
                && in_array(($item['notification']?->payload['source'] ?? ''), [
                    'team.join_request.accepted', 'team.join_request.rejected',
                    'team.join_request.blocked', 'team.join_request.unblocked',
                ], true));
            if (! $hasReviewEvent) {
                $rest->push([
                    'key' => 'decision-'.$request->id,
                    'kind' => 'system',
                    'heading' => 'Решение по заявке: '.$request->status->label(),
                    'body' => $request->review_reason ?: 'Статус заявки изменён.',
                    'created_at' => $request->reviewed_at,
                    'sender' => null, 'mine' => false, 'notification' => null,
                ]);
            }
        }

        return collect([$initial])->concat($rest->sort(function (array $a, array $b): int {
            return ($a['created_at']?->getTimestamp() <=> $b['created_at']?->getTimestamp())
                ?: strcmp((string) $a['key'], (string) $b['key']);
        }))->values();
    }
}
