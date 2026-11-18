<?php

namespace App\Modules\Invitation\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountInvitationEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(AccountInvitation::class, 'invitation_id');
    }
}
