<?php

namespace Tests\Feature\Invitation;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Invitation\Application\Services\AccountInvitationManager;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class AccountInvitationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function invitation(): array
    {
        config()->set('mail.default', 'smtp');
        config()->set('features.venue_rental.rental_flow', true);
        Mail::fake();

        $issuer = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $venue = Venue::factory()->create();
        [$invitation] = app(AccountInvitationManager::class)->create(
            $issuer, 'lifecycle@example.test', 'venue', $venue->id,
            'owner', 'Lifecycle test', null, 7,
        );

        return [$issuer, $invitation];
    }

    public function test_accepted_invitation_is_not_manageable_while_ownership_is_pending(): void
    {
        [$issuer, $invitation] = $this->invitation();
        $user = User::factory()->create();
        $manager = app(AccountInvitationManager::class);
        $manager->accept($invitation, $user);
        $invitation->refresh();

        $this->assertSame('pending', $invitation->status);
        $this->assertSame('Принято', $invitation->invitationStatusLabel());
        $this->assertFalse($invitation->canManageLink());

        try {
            $manager->renew($invitation, $issuer, 14);
            $this->fail('Accepted invitation must not be renewed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('days', $exception->errors());
        }

        try {
            $manager->revoke($invitation, $issuer);
            $this->fail('Accepted invitation must not be revoked.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $invitation->refresh();
        $this->assertSame('pending', $invitation->status);
        $this->assertSame($user->id, $invitation->accepted_user_id);
        $this->assertNull($invitation->revoked_at);
    }

    public function test_renewal_records_actor_and_both_expiration_dates(): void
    {
        [$issuer, $invitation] = $this->invitation();
        $oldExpiry = $invitation->expires_at->toIso8601String();

        app(AccountInvitationManager::class)->renew($invitation, $issuer, 14);
        $invitation->refresh();

        $event = $invitation->events()->where('event', 'link_renewed')->firstOrFail();
        $this->assertSame($issuer->id, $event->details['issuer_id']);
        $this->assertSame($oldExpiry, $event->details['previous_expires_at']);
        $this->assertSame($invitation->expires_at->toIso8601String(), $event->details['expires_at']);
        $this->assertTrue($invitation->canManageLink());
    }
}
