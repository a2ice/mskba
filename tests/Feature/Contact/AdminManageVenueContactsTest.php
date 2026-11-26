<?php

namespace Tests\Feature\Contact;

use App\Modules\Contact\Application\Exceptions\ContactVerificationException;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Contact\Presentation\Mail\ContactVerificationCodeMail;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\UseCases\AdminManageVenueContactsHandler;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminManageVenueContactsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
    }

    public function test_directory_contact_does_not_claim_user_identity_and_primary_is_unique(): void
    {
        $actor = $this->admin();
        $venue = Venue::factory()->create();
        $other = Venue::factory()->create();
        $userContact = $actor->contacts()->create([
            'type' => 'email', 'value' => 'desk@example.test', 'verified_at' => now(),
        ]);
        $handler = app(AdminManageVenueContactsHandler::class);
        $first = $handler->add($actor, $venue, [
            'type' => 'email', 'value' => 'DESK@example.test', 'label' => 'Бронирование', 'comment' => 'Внутреннее примечание',
        ]);
        $second = $handler->add($actor, $venue, ['type' => 'phone', 'value' => '+7 (900) 123-45-67']);
        $handler->add($actor, $other, ['type' => 'email', 'value' => 'desk@example.test']);
        $this->assertSame('desk@example.test', $first->value);
        $this->assertSame('Внутреннее примечание', $first->comment);
        $this->assertArrayNotHasKey('comment', $first->toArray());
        $this->assertNull($first->verified_at);
        $this->assertFalse($first->is_public);
        $this->assertTrue($first->is_primary);
        $this->assertNotNull($userContact->fresh()->verified_at);
        $this->assertSame('+79001234567', $second->value);
        $handler->setPrimary($actor, $venue, $second->id);
        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $handler->delete($actor, $venue, $second->id);
        $this->assertTrue($first->fresh()->is_primary);
        $this->assertSoftDeleted('contacts', ['id' => $second->id]);

        $this->expectException(ValidationException::class);
        $handler->add($actor, $venue, ['type' => 'email', 'value' => 'DESK@example.test']);
    }

    public function test_publication_is_independent_of_venue_contact_verification(): void
    {
        Mail::fake();
        $actor = $this->admin();
        $venue = Venue::factory()->create();
        $handler = app(AdminManageVenueContactsHandler::class);
        $email = $handler->add($actor, $venue, [
            'type' => 'email', 'value' => 'public@example.test', 'is_public' => true,
        ]);
        $this->assertTrue($email->is_public);
        $this->assertNull($email->verified_at);
        $phone = $handler->add($actor, $venue, [
            'type' => 'phone', 'value' => '+79001234567',
        ]);
        $this->assertFalse($phone->is_public);
        $handler->edit($actor, $venue, $phone->id, [
            'type' => 'phone', 'value' => $phone->value, 'is_public' => true,
        ]);
        $this->assertTrue($phone->fresh()->is_public);
        $this->assertNull($phone->fresh()->verified_at);
        $handler->edit($actor, $venue, $email->id, [
            'type' => 'email', 'value' => $email->value, 'is_public' => false,
        ]);
        $this->assertFalse($email->fresh()->is_public);
        $this->assertNull($email->fresh()->verified_at);
        $this->assertFalse($email->verifications()->exists());
        $this->assertFalse($phone->verifications()->exists());
        Mail::assertNothingSent();
    }

    public function test_venue_contact_mutations_cannot_target_another_venue_or_user(): void
    {
        $actor = $this->admin();
        $venue = Venue::factory()->create();
        $other = Venue::factory()->create();
        $handler = app(AdminManageVenueContactsHandler::class);
        $contact = $handler->add($actor, $other, ['type' => 'email', 'value' => 'other@example.test']);
        $userContact = $actor->contacts()->create(['type' => 'email', 'value' => 'personal@example.test']);
        foreach ([$contact, $userContact] as $foreign) {
            try {
                $handler->edit($actor, $venue, $foreign->id, ['type' => 'email', 'value' => 'changed@example.test']);
                $this->fail('Foreign contact must not be editable.');
            } catch (ModelNotFoundException) {
                $this->assertNotSame('changed@example.test', $foreign->fresh()->value);
            }
        }
        $this->expectException(AuthorizationException::class);
        $handler->add(User::factory()->create(['system_role' => UserSystemRoleEnum::USER]), $venue, [
            'type' => 'email', 'value' => 'denied@example.test',
        ]);
    }

    public function test_otp_attempts_and_verification_reset_when_address_changes(): void
    {
        config()->set('mail.default', 'smtp');
        Mail::fake();
        $actor = $this->admin();
        $venue = Venue::factory()->create();
        $handler = app(AdminManageVenueContactsHandler::class);
        $contact = $handler->add($actor, $venue, ['type' => 'email', 'value' => 'old@example.test']);
        $handler->sendCode($actor, $venue, $contact->id);
        $code = Mail::sent(ContactVerificationCodeMail::class)->sole()->code;
        try {
            $handler->confirmCode($actor, $venue, $contact->id, $code === '111111' ? '222222' : '111111');
            $this->fail('Invalid OTP must fail.');
        } catch (ContactVerificationException) {
            $this->assertSame(1, $contact->verifications()->sole()->attempts_count);
        }
        $handler->confirmCode($actor, $venue, $contact->id, $code);
        $this->assertNotNull($contact->fresh()->verified_at);
        $handler->edit($actor, $venue, $contact->id, ['type' => 'email', 'value' => 'old@example.test', 'is_public' => true]);
        $this->assertTrue($contact->fresh()->is_public);
        $handler->edit($actor, $venue, $contact->id, ['type' => 'email', 'value' => 'new@example.test', 'is_public' => true]);
        $this->assertNull($contact->fresh()->verified_at);
        $this->assertFalse($contact->fresh()->is_public);
        $this->assertSame(ContactVerificationStatusEnum::CONFIRMED, $contact->verifications()->sole()->status);

        $handler->sendCode($actor, $venue, $contact->id);
        $pending = $contact->verifications()->where('status', ContactVerificationStatusEnum::PENDING->value)->sole();
        $handler->edit($actor, $venue, $contact->id, ['type' => 'email', 'value' => 'third@example.test']);
        $this->assertSame(ContactVerificationStatusEnum::CANCELLED, $pending->fresh()->status);
        $this->expectException(ContactVerificationException::class);
        $handler->confirmCode($actor, $venue, $contact->id, $code);
    }
}
