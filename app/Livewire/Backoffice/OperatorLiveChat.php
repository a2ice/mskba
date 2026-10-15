<?php

namespace App\Livewire\Backoffice;

use App\Filament\Backoffice\Resources\OperatorConversations\OperatorConversationResource;
use App\Modules\Notification\Application\Services\OperatorConversationAccess;
use App\Modules\Notification\Application\Services\OperatorConversationMessaging;
use App\Modules\Notification\Domain\Models\OperatorConversation;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class OperatorLiveChat extends Component
{
    public int $conversationId;

    public string $draft = '';

    public int $visibleLimit = 30;

    public function mount(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        $this->authorized();
    }

    private function authorized(): OperatorConversation
    {
        $record = OperatorConversation::query()->with(['operator', 'recipient'])->findOrFail($this->conversationId);
        abort_unless(OperatorConversationResource::canView($record), 403);

        return $record;
    }

    public function loadOlder(): void
    {
        $this->authorized();
        $this->visibleLimit = min(100, $this->visibleLimit + 30);
    }

    public function refreshConversation(): void
    {
        $this->authorized();
    }

    public function send(OperatorConversationMessaging $messaging): void
    {
        $conversation = $this->authorized();
        $actor = auth('web')->user()->canonical();
        abort_unless(app(OperatorConversationAccess::class)->canWrite($actor, $conversation), 403);
        $data = $this->validate(['draft' => ['required', 'string', 'max:2000']]);
        if (trim($data['draft']) === '') {
            throw ValidationException::withMessages(['draft' => 'Введите сообщение.']);
        }

        $messaging->send($actor, $conversation, $data['draft']);
        $this->reset('draft');
        $this->dispatch('operator-chat-sent');
    }

    public function render()
    {
        $conversation = $this->authorized();
        $this->visibleLimit = max(10, min(100, $this->visibleLimit));
        $rows = $conversation->messages()->with('sender')->orderByDesc('id')
            ->limit($this->visibleLimit + 1)->get();
        $hasOlder = $rows->count() > $this->visibleLimit;
        $messages = $rows->take($this->visibleLimit)->reverse()->values();
        $actor = auth('web')->user()->canonical();

        return view('livewire.backoffice.operator-live-chat', [
            'conversation' => $conversation,
            'messages' => $messages,
            'hasOlder' => $hasOlder && $this->visibleLimit < 100,
            'moreOnPortal' => $hasOlder && $this->visibleLimit >= 100,
            'canSend' => app(OperatorConversationAccess::class)->canWrite($actor, $conversation),
            'viewer' => $actor,
        ]);
    }
}
