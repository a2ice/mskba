<?php

namespace App\Livewire\Backoffice;

use App\Filament\Backoffice\Resources\SupportTickets\SupportTicketResource;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\SupportTicketAccess;
use App\Modules\Notification\Application\Services\SupportTicketMessagingService;
use App\Modules\Notification\Application\Services\SupportTicketTimeline;
use App\Modules\Notification\Domain\Models\SupportTicket;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class SupportLiveChat extends Component
{
    public int $ticketId;

    public string $draft = '';

    public int $visibleLimit = 30;

    public function mount(int $ticketId): void
    {
        $this->ticketId = $ticketId;
        $this->authorizedTicket();
    }

    private function authorizedTicket(): SupportTicket
    {
        $ticket = SupportTicket::query()->with('thread.lead', 'user')->findOrFail($this->ticketId);
        abort_unless(SupportTicketResource::canView($ticket), 403);

        return $ticket;
    }

    public function loadOlder(): void
    {
        $this->authorizedTicket();
        $this->visibleLimit = min(100, $this->visibleLimit + 30);
    }

    public function refreshConversation(): void
    {
        $this->authorizedTicket();
    }

    public function send(SupportTicketMessagingService $messaging): void
    {
        $ticket = $this->authorizedTicket();
        /** @var User $actor */
        $actor = auth('web')->user()->canonical();
        $data = $this->validate([
            'draft' => ['required', 'string', 'max:2000'],
        ]);
        if (trim($data['draft']) === '') {
            throw ValidationException::withMessages(['draft' => 'Введите сообщение.']);
        }
        abort_unless($ticket->status === 'open'
            && app(SupportTicketAccess::class)->isAgent($actor), 403);
        $messaging->send($actor, $ticket->id, $data['draft'], agentOnly: true);
        $this->reset('draft');
        $this->dispatch('support-chat-sent');
    }

    public function render()
    {
        $ticket = $this->authorizedTicket();
        $thread = $ticket->thread;
        abort_unless($thread !== null, 404);
        $actor = auth('web')->user()->canonical();
        $this->visibleLimit = max(10, min(100, $this->visibleLimit));
        $history = app(SupportTicketTimeline::class)->page($thread, limit: $this->visibleLimit);
        $canSend = $ticket->status === 'open'
            && app(SupportTicketAccess::class)->canWrite($actor, $ticket, $thread)
            && app(SupportTicketAccess::class)->isAgent($actor);

        return view('livewire.backoffice.support-live-chat', [
            'ticket' => $ticket,
            'thread' => $thread,
            'messages' => $history['entries'],
            'hasOlder' => $history['next_before'] !== null && $this->visibleLimit < 100,
            'moreOnPortal' => $history['next_before'] !== null && $this->visibleLimit >= 100,
            'canSend' => $canSend,
            'viewer' => $actor,
        ]);
    }
}
