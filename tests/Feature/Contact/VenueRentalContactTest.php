<?php

namespace Tests\Feature\Contact;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueRentalContact;
use App\Modules\Venue\Application\UseCases\AdminManageVenueContactsHandler;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class VenueRentalContactTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
    }

    public function test_explicit_destination_is_unique_private_and_not_inferred_from_primary(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $venue = Venue::factory()->create();
        $handler = app(AdminManageVenueContactsHandler::class);
        $resolver = app(VenueRentalContact::class);
        $first = $handler->add($admin, $venue, ['type' => 'email', 'value' => 'one@example.test', 'is_public' => true]);
        $second = $handler->add($admin, $venue, ['type' => 'email', 'value' => 'two@example.test']);
        $first->update(['verified_at' => now()]);
        $second->update(['verified_at' => now()]);
        $this->assertNull($resolver->resolve($venue));

        $handler->setRentalContact($admin, $venue, $first->id);
        $handler->setRentalContact($admin, $venue, $second->id);
        $this->assertFalse($first->fresh()->is_rental_contact);
        $this->assertTrue($first->fresh()->is_primary);
        $this->assertFalse($second->fresh()->is_public);
        $this->assertSame($second->id, $resolver->resolve($venue)?->id);
        $this->assertArrayNotHasKey('is_rental_contact', $second->fresh()->toArray());

        $handler->edit($admin, $venue, $second->id, ['type' => 'email', 'value' => $second->value, 'label' => 'Отдел аренды']);
        $this->assertSame($second->id, $resolver->resolve($venue)?->id);
        $handler->clearRentalContact($admin, $venue, $second->id);
        $this->assertNull($resolver->resolve($venue));

        $handler->setRentalContact($admin, $venue, $second->id);
        $handler->edit($admin, $venue, $second->id, ['type' => 'email', 'value' => 'new@example.test']);
        $this->assertFalse($second->fresh()->is_rental_contact);
        $this->assertNull($resolver->resolve($venue));
        $second->fresh()->update(['verified_at' => now()]);
        $this->assertNull($resolver->resolve($venue));
        $handler->setRentalContact($admin, $venue, $second->id);
        $handler->delete($admin, $venue, $second->id);
        $this->assertNull($resolver->resolve($venue));
        $this->assertDatabaseHas('contacts', ['id' => $second->id, 'is_rental_contact' => false]);
        Mail::assertNothingSent();
    }

    public function test_assignment_rejects_unverified_phone_foreign_and_unauthorized_contacts(): void
    {
        $admin = $this->admin();
        $venue = Venue::factory()->create();
        $other = Venue::factory()->create();
        $handler = app(AdminManageVenueContactsHandler::class);
        $email = $handler->add($admin, $venue, ['type' => 'email', 'value' => 'pending@example.test', 'is_public' => true]);
        $phone = $handler->add($admin, $venue, ['type' => 'phone', 'value' => '+79001234567']);
        $phone->update(['verified_at' => now()]);
        foreach ([$email, $phone] as $contact) {
            try {
                $handler->setRentalContact($admin, $venue, $contact->id);
                $this->fail('Ineligible rental destination accepted.');
            } catch (ValidationException) {
                $this->assertFalse($contact->fresh()->is_rental_contact);
            }
        }
        $foreign = $handler->add($admin, $other, ['type' => 'email', 'value' => 'other@example.test']);
        $foreign->update(['verified_at' => now()]);
        $personal = $admin->contacts()->create(['type' => 'email', 'value' => 'personal@example.test', 'verified_at' => now()]);
        foreach ([$foreign, $personal] as $contact) {
            try {
                $handler->setRentalContact($admin, $venue, $contact->id);
                $this->fail('Foreign rental destination accepted.');
            } catch (ModelNotFoundException) {
                $this->assertFalse($contact->fresh()->is_rental_contact);
            }
        }
        $email->update(['verified_at' => now()]);
        $this->expectException(AuthorizationException::class);
        $handler->setRentalContact(User::factory()->create(['system_role' => UserSystemRoleEnum::USER]), $venue, $email->id);
    }

    public function test_revoked_verification_clears_the_destination(): void
    {
        $admin = $this->admin();
        $venue = Venue::factory()->create();
        $handler = app(AdminManageVenueContactsHandler::class);
        $email = $handler->add($admin, $venue, ['type' => 'email', 'value' => 'desk@example.test']);
        $email->update(['verified_at' => now()]);
        $handler->setRentalContact($admin, $venue, $email->id);
        $email->fresh()->update(['verified_at' => null]);
        $this->assertFalse($email->fresh()->is_rental_contact);
        $this->assertNull(app(VenueRentalContact::class)->resolve($venue));
    }
}
