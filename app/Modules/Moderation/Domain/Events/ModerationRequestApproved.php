<?php

namespace App\Modules\Moderation\Domain\Events;

use App\Modules\Moderation\Domain\Models\ModerationRequest;
use Illuminate\Queue\SerializesModels;

final readonly class ModerationRequestApproved
{
    use SerializesModels;

    public function __construct(
        public ModerationRequest $request,
    ) {}
}
