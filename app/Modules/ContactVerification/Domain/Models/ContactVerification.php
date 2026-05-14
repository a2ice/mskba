<?php

namespace App\Modules\ContactVerification\Domain\Models;

use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\ContactVerification\Domain\Enums\ContactVerificationPurposeEnum;
use App\Modules\ContactVerification\Domain\Enums\ContactVerificationStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['contact_id', 'purpose', 'status', 'value', 'payload', 'expires_at', 'verified_at'])]
class ContactVerification extends Model
{
    protected function casts(): array
    {
        return [
            'purpose' => ContactVerificationPurposeEnum::class,
            'status' => ContactVerificationStatusEnum::class,
            'payload' => 'array',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
