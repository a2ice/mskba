<?php

namespace App\Modules\Ai\Domain\Models;

use App\Modules\Ai\Domain\Enums\PlayerCharacterGenerationStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'user_id',
    'provider',
    'status',
    'reference_media_ids',
    'payload_snapshot',
    'result_disk',
    'result_path',
    'result_mime',
    'result_size',
    'provider_run_id',
    'error_code',
    'error_message',
    'started_at',
    'completed_at',
    'failed_at',
    'expires_at',
])]
final class PlayerCharacterGeneration extends Model
{
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PlayerCharacterGenerationStatusEnum::class,
            'reference_media_ids' => 'array',
            'payload_snapshot' => 'array',
            'result_size' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
