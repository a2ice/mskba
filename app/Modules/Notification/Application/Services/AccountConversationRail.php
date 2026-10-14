<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Notification\Application\UseCases\GetSystemConversationPreviewHandler;
use App\Modules\Notification\Application\UseCases\ListAccountConversationsHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Same privacy-aware conversation discovery as the /account/notifications index. */
final class AccountConversationRail
{
    public function __construct(
        private readonly ListAccountConversationsHandler $conversations,
        private readonly GetSystemConversationPreviewHandler $system,
        private readonly CurrentActorResolver $actors,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function items(User $user, Request $request): Collection
    {
        $user = $user->canonical();
        $cards = $this->conversations->handle($user, $this->actors->resolveForRequest($request));
        $system = $this->system->handle($user);
        if ($system) {
            $preview = $system['preview'];
            $cards->push([
                'id' => 'system',
                'name' => 'Система MSKBA',
                'preview' => $preview->title.' — '.$preview->body,
                'unread_count' => $system['unread_count'],
                'date' => $system['last']->created_at,
                'status' => null,
                'url' => route('account.notifications.threads.system'),
            ]);
        }

        return $cards->sortByDesc('date')->values();
    }
}
