<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Identity\Application\Services\UserPrivacyAccessService;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\DirectConversation;

/** An outgoing direct message is an explicit, participant-scoped invitation to reply. */
final class DirectMessageRecipientAccess
{
    public function __construct(private readonly UserPrivacyAccessService $privacy) {}

    /** UI affordance only; the send endpoint repeats authorization inside its transaction. */
    public function canSend(User $recipient, ?User $sender, DirectMessageBlockService $blocks): bool
    {
        if ($sender === null) {
            return false;
        }

        $recipient = $recipient->canonical();
        $sender = $sender->canonical();

        return ! $sender->isSameIdentity($recipient)
            && ! $sender->isBlocked() && ! $sender->trashed()
            && ! $recipient->isBlocked() && ! $recipient->trashed()
            && ! $blocks->blockedBetween($sender, $recipient)
            && $this->allows($recipient, $sender);
    }

    public function allows(User $recipient, User $sender): bool
    {
        $recipient = $recipient->canonical();
        $sender = $sender->canonical();

        if ($recipient->isSameIdentity($sender)) {
            return false;
        }

        if ($this->privacy->allows($recipient, $sender, UserPrivacySettingTypeEnum::MESSAGES)) {
            return true;
        }

        // A conversation alone is not consent: the recipient must have personally
        // sent an actual message to this sender. System/block events never qualify.
        $ids = [(int) $recipient->id, (int) $sender->id];
        sort($ids);

        return DirectConversation::query()
            ->where('user_low_id', $ids[0])
            ->where('user_high_id', $ids[1])
            ->whereHas('messages', fn ($query) => $query
                ->where('sender_user_id', $recipient->id)
                ->where('kind', 'message'))
            ->exists();
    }
}
