<?php

namespace App\Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'fingerprint_hash',
    'browser_signature_hash',
    'ip_hash',
    'visits_count',
    'first_seen_at',
    'last_seen_at',
])]
class UserFingerprint extends Model
{
    protected function casts(): array
    {
        return [
            'visits_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this
            ->belongsToMany(User::class, 'user_fingerprint_user')
            ->withPivot([
                'authentications_count',
                'first_authenticated_at',
                'last_authenticated_at',
            ])
            ->withTimestamps();
    }
}
