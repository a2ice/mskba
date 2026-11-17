<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\SupportTicket;
use App\Modules\Notification\Infrastructure\Broadcasting\ConversationChangedBroadcast;
use Illuminate\Support\Facades\DB;

/**
 * One write path for portal and Filament. Never authorize against a cached
 * ticket status: take a row lock, re-read the ticket and check current grants.
 */
final class SupportTicketMessagingService
{
    public function __construct(
        private readonly SupportTicketAccess $access,
        private readonly SupportTicketMessageNotifier $notifier,
    ) {}

    public function send(User $actor, int $ticketId, string $body, bool $agentOnly = false): void
    {
        $actor = $actor->canonical();
        $body = trim($body);
        abort_if($body === '' || mb_strlen($body) > 2000, 422);
        abort_if($actor->isBlocked() || $actor->trashed(), 403);

        [$ticket, $thread] = DB::transaction(function () use ($actor, $ticketId, $body, $agentOnly): array {
            $ticket = SupportTicket::query()->whereKey($ticketId)->lockForUpdate()->firstOrFail();
            $thread = $ticket->thread()->firstOrFail();

            abort_unless($this->access->canWrite($actor, $ticket, $thread), 403);
            if ($agentOnly) {
                abort_unless($this->access->isAgent($actor), 403);
            }

            $thread->messages()->create([
                'sender_user_id' => $actor->id,
                'body' => $body,
            ]);
            $thread->touch();
            $ticket->touch();

            return [$ticket, $thread];
        });

        $this->notifier->notify($ticket, $thread, $actor, $body);
        try {
            ConversationChangedBroadcast::dispatch('support', $thread->id);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
