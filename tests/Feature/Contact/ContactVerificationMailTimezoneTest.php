<?php

namespace Tests\Feature\Contact;

use App\Modules\Contact\Domain\Models\ContactVerification;
use App\Modules\Contact\Presentation\Mail\ContactVerificationCodeMail;
use Tests\TestCase;

final class ContactVerificationMailTimezoneTest extends TestCase
{
    public function test_expiration_displays_moscow_time_even_when_date_is_in_utc(): void
    {
        $originalTimezone = date_default_timezone_get();

        try {
            date_default_timezone_set('UTC');

            $verification = new ContactVerification();
            $verification->setRawAttributes([
                'expires_at' => '2026-10-12 16:58:00',
            ], true);

            $expiresAt = $verification->expires_at->getTimestamp();
            $html = (new ContactVerificationCodeMail($verification, '552584'))->render();

            $this->assertStringContainsString('552584', $html);
            $this->assertStringContainsString('12.10.2026 19:58 (МСК)', $html);
            $this->assertStringNotContainsString('12.10.2026 16:58', $html);
            $this->assertSame($expiresAt, $verification->expires_at->getTimestamp());
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function test_expiration_already_in_moscow_is_not_shifted_again(): void
    {
        $originalTimezone = date_default_timezone_get();

        try {
            date_default_timezone_set('Europe/Moscow');

            $verification = new ContactVerification();
            $verification->setRawAttributes([
                'expires_at' => '2026-10-12 19:58:00',
            ], true);

            $html = (new ContactVerificationCodeMail($verification, '123456'))->render();

            $this->assertStringContainsString('12.10.2026 19:58 (МСК)', $html);
            $this->assertStringNotContainsString('12.10.2026 22:58', $html);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }
}
