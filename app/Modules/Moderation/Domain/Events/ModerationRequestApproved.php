<?php

namespace App\Modules\Moderation\Domain\Events;

use App\Modules\Moderation\Domain\Models\ModerationRequest;

final readonly class ModerationRequestApproved
{
    public function __construct(
        public ModerationRequest $request,
    ) {}
}
