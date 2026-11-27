<?php

namespace Tests\Unit\Contact;

use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum as Status;
use App\Modules\Contact\Domain\Models\ContactVerification;
use Tests\TestCase;

final class ContactVerificationEffectiveStatusTest extends TestCase
{
    public function test_pending_status_is_derived_from_expiry_without_mutating_record(): void
    {
        $this->freezeTime();
        $attempt = new ContactVerification(['status' => Status::PENDING, 'expires_at' => now()->addMinute()]);
        $this->assertSame(Status::PENDING, $attempt->effectiveStatus());
        $this->travel(2)->minutes();
        $this->assertSame(Status::EXPIRED, $attempt->effectiveStatus());
        $this->assertSame(Status::PENDING, $attempt->status);
        $attempt->expires_at = null;
        $this->assertSame(Status::EXPIRED, $attempt->effectiveStatus());
    }

    public function test_terminal_status_is_preserved_after_expiry(): void
    {
        foreach ([Status::CONFIRMED, Status::FAILED, Status::CANCELLED, Status::EXPIRED] as $status) {
            $attempt = new ContactVerification(['status' => $status, 'expires_at' => now()->subDay()]);
            $this->assertSame($status, $attempt->effectiveStatus());
        }
    }
}
