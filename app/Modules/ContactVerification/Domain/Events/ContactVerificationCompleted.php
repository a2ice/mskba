<?php

namespace App\Modules\ContactVerification\Domain\Events;

use App\Modules\ContactVerification\Domain\Enums\ContactVerificationPurposeEnum;

final readonly class ContactVerificationCompleted
{
    public function __construct(
        public int $verificationId,
        public int $contactId,
        public ContactVerificationPurposeEnum $purpose,
    ) {
    }
}
