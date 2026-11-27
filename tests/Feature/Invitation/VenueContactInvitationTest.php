<?php

namespace Tests\Feature\Invitation;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Invitation\Application\Services\AccountInvitationManager;
use App\Modules\Invitation\Presentation\Mail\AccountInvitationMail;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class VenueContactInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_from_verified_contact_preserves_source_and_blocks_duplicate(): void
    {
        config()->set('mail.default', 'smtp');
        Mail::fake();
        $actor = User::factory()->create(['system_role' => UserSystemRoleEnum::SUPERADMIN, 'status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create();
        $contact = $venue->contacts()->create(['type' => 'email', 'value' => 'office@example.test', 'verified_at' => now()]);
        $manager = app(AccountInvitationManager::class);
        [$invitation] = $manager->create($actor, $contact->value, 'venue', $venue->id, 'owner', '', null, 7, $contact->id);
        $this->assertSame($contact->value, $invitation->contact_value);
        $this->assertSame($contact->id, $invitation->events()->where('event', 'created')->sole()->details['venue_contact_id']);
        $this->assertNull($invitation->accepted_user_id);
        Mail::assertSentCount(1);
        $this->expectException(ValidationException::class);
        $manager->create($actor, $contact->value, 'venue', $venue->id, 'owner', '', null, 7, $contact->id);
    }

    public function test_source_contact_must_be_verified_current_and_belong_to_venue(): void
    {
        config()->set('mail.default', 'smtp');
        Mail::fake();
        $actor = User::factory()->create(['system_role' => UserSystemRoleEnum::SUPERADMIN, 'status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create();
        $other = Venue::factory()->create();
        $contact = $venue->contacts()->create(['type' => 'email', 'value' => 'office@example.test', 'is_public' => true]);
        $manager = app(AccountInvitationManager::class);
        foreach (['office@example.test', 'old@example.test'] as $email) {
            try {
                $manager->create($actor, $email, 'venue', $venue->id, 'owner', '', null, 7, $contact->id);
                $this->fail('Unverified or stale contact must be rejected.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('account_invitations', 0);
            }
            $contact->update(['verified_at' => now()]);
        }
        try {
            $manager->create($actor, $contact->value, 'venue', $other->id, 'owner', '', null, 7, $contact->id);
            $this->fail('Foreign contact must be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('account_invitations', 0);
        }
        Mail::assertNotSent(AccountInvitationMail::class);
    }
}
