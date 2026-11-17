<?php

namespace App\Modules\Notification\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\SupportTicketAccess;
use App\Modules\Notification\Application\Services\SupportTicketMessageNotifier;
use App\Modules\Notification\Domain\Models\RequestThread;
use App\Modules\Notification\Domain\Models\SupportTicket;
use App\Modules\Notification\Infrastructure\Broadcasting\ConversationChangedBroadcast;
use Illuminate\Support\Facades\DB;

final class CreateSupportTicketHandler
{
    public function __construct(
        private readonly SupportTicketAccess $access,
        private readonly SupportTicketMessageNotifier $notifier,
    ) {}

    public function handle(User $actor, string $subject, string $body): SupportTicket
    {
        $user = $actor->canonical();
        abort_if($user->isBlocked() || $user->trashed(), 403);

        [$ticket, $thread] = DB::transaction(function () use ($user, $subject, $body): array {
            $lead = User::query()->where('system_role', UserSystemRoleEnum::SUPERADMIN->value)
                ->where('status', 'confirmed')->orderBy('id')->get()
                ->first(fn (User $candidate) => $this->access->isAgent($candidate)
                    && $this->access->afterId($candidate) !== null);
            $ticket = SupportTicket::query()->create([
                'user_id' => $user->id,
                'subject' => trim($subject),
                'status' => 'open',
            ]);
            $thread = RequestThread::query()->create([
                'type' => 'support',
                'subject_id' => $ticket->id,
                'lead_user_id' => $lead?->id,
            ]);
            if ($lead) {
                $thread->memberships()->create(['user_id' => $lead->id, 'joined_at' => now()]);
            }
            $thread->messages()->create([
                'sender_user_id' => $user->id,
                'body' => trim($body),
            ]);

            return [$ticket, $thread];
        });

        $this->notifier->notify($ticket, $thread, $user, $body);
        try {
            ConversationChangedBroadcast::dispatch('support', $thread->id);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $ticket;
    }
}
