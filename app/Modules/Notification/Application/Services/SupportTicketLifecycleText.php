<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Notification\Domain\Enums\SupportClosureReasonEnum;

/** One format shared by the chat lifecycle event and the closed-ticket footer. */
final class SupportTicketLifecycleText
{
    public function closed(?string $actor, ?string $reason, ?string $description): string
    {
        $name = trim((string) $actor);
        $message = 'Обращение закрыл @'.($name !== '' ? $name : 'сотрудник_поддержки').'.';
        $reasonText = $this->reason($reason, $description);

        if (filled($reasonText)) {
            $message .= ' Причина: '.$reasonText;
            if (! preg_match('/[.!?…]\z/u', $reasonText)) {
                $message .= '.';
            }
        }

        return $message;
    }

    public function reopened(?string $actor): string
    {
        $name = trim((string) $actor);

        return 'Обращение возобновил @'.($name !== '' ? $name : 'сотрудник_поддержки').'.';
    }

    public function reason(?string $reason, ?string $description): ?string
    {
        $reasonEnum = SupportClosureReasonEnum::tryFrom((string) $reason);
        $reasonText = $reasonEnum === SupportClosureReasonEnum::OTHER
            ? trim((string) $description)
            : $reasonEnum?->label();

        return filled($reasonText) ? $reasonText : null;
    }
}
