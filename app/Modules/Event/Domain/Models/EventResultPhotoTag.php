<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Domain\Models\Media;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['media_id', 'user_id', 'position_x', 'position_y'])]
class EventResultPhotoTag extends Model
{
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'position_x' => 'float',
            'position_y' => 'float',
        ];
    }
}
