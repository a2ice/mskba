<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['event_participant_id', 'permission'])]
class EventResponsibilityPermission extends Model
{
    public function participant(): BelongsTo
    {
        return $this->belongsTo(EventParticipant::class, 'event_participant_id');
    }

    protected function casts(): array
    {
        return ['permission' => EventResponsibilityPermissionEnum::class];
    }
}
