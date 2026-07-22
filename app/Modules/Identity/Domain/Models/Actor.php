<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Identity\Domain\Enums\ActorTypeEnum;
use App\Modules\Identity\Infrastructure\Database\Factories\ActorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'actor_key',
    'type',
    'user_id',
    'user_fingerprint_id',
])]
class Actor extends Model
{
    /** @use HasFactory<ActorFactory> */
    use HasFactory;

    protected static function newFactory(): ActorFactory
    {
        return ActorFactory::new();
    }

    protected function casts(): array
    {
        return [
            'type' => ActorTypeEnum::class,
            'user_id' => 'integer',
            'user_fingerprint_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fingerprint(): BelongsTo
    {
        return $this->belongsTo(UserFingerprint::class, 'user_fingerprint_id');
    }
}
