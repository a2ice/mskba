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

    public function test_accepted_invitation_prevents_duplicate_after_link_expiry(): void
    {
        [$issuer, $invitation] = $this->invitation();
        $manager = app(AccountInvitationManager::class);
        $manager->accept($invitation, User::factory()->create());
        $this->travel(8)->days();
        $invitation->refresh();

        $this->assertTrue($invitation->isExpired());
        $this->assertSame('pending', $invitation->effectiveStatus());
        $this->assertFalse($invitation->canManageLink());

        try {
            $manager->create(
                $issuer, $invitation->contact_value, 'venue', $invitation->scope_id,
                'owner', 'Duplicate', null, 7,
            );
            $this->fail('An accepted pending invitation must prevent duplicates after link expiry.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }

        $this->assertSame(1, $invitation->newQuery()
            ->where('contact_value', $invitation->contact_value)
            ->where('scope_id', $invitation->scope_id)->count());
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

    public function test_restore_rotates_link_preserves_history_and_rejects_stale_acceptance(): void
    {
        [$issuer, $invitation] = $this->invitation();
        $manager = app(AccountInvitationManager::class);
        $stale = clone $invitation;
        $previousHash = $invitation->token_hash;
        $previousExpiry = $invitation->expires_at->toIso8601String();
        $manager->revoke($invitation, $issuer);
        $invitation->refresh();
        $this->assertTrue($invitation->canRestoreLink());
        $this->assertSame('Аннулировано', $invitation->systemStatusLabel());
        $previousRevocation = $invitation->revoked_at->toIso8601String();

        $url = $manager->restore($invitation, $issuer, 14, 'Получатель снова готов подключиться');
        $invitation->refresh();

        $this->assertSame('pending', $invitation->status);
        $this->assertSame('Восстановлено', $invitation->systemStatusLabel());
        $this->assertFalse($invitation->canRestoreLink());
        $this->assertNull($invitation->revoked_at);
        $this->assertNotSame($previousHash, $invitation->token_hash);
        $this->assertNull($invitation->accepted_user_id);
        $token = basename(parse_url($url, PHP_URL_PATH));
        $this->assertSame($invitation->id, $manager->findByToken($token)->id);
        $event = $invitation->events()->where('event', 'restored')->firstOrFail();
        $this->assertSame($issuer->id, $event->details['issuer_id']);
        $this->assertSame('Получатель снова готов подключиться', $event->details['reason']);
        $this->assertSame($previousRevocation, $event->details['previous_revoked_at']);
        $this->assertSame($previousExpiry, $event->details['previous_expires_at']);
        $this->assertSame($invitation->expires_at->toIso8601String(), $event->details['expires_at']);
        $this->assertTrue($invitation->events()->where('event', 'revoked')->exists());

        $manager->seen($stale);
        $this->assertSame(0, $invitation->fresh()->views_count);
        try {
            $manager->accept($stale, User::factory()->create());
            $this->fail('A stale token revision must not be accepted after restoration.');
        } catch (HttpException $exception) {
            $this->assertSame(410, $exception->getStatusCode());
        }
        $this->assertNull($invitation->fresh()->accepted_user_id);
    }

    public function test_restore_rejects_duplicate_live_invitation(): void
    {
        [$issuer, $invitation] = $this->invitation();
        $manager = app(AccountInvitationManager::class);
        $manager->revoke($invitation, $issuer);
        $manager->create($issuer, $invitation->contact_value, 'venue', $invitation->scope_id,
            'owner', 'New invitation', null, 7);

        try {
            $manager->restore($invitation, $issuer, 7, 'Повторное подключение');
            $this->fail('Restoration must not create a duplicate active invitation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }
        $this->assertSame('revoked', $invitation->fresh()->status);
        $this->assertFalse($invitation->events()->where('event', 'restored')->exists());
    }

    public function test_renewal_rejects_duplicate_after_expiry(): void
    {
        [$issuer, $invitation] = $this->invitation();
        $manager = app(AccountInvitationManager::class);
        $this->travel(8)->days();
        $manager->create($issuer, $invitation->contact_value, 'venue', $invitation->scope_id,
            'owner', 'Replacement invitation', null, 7);

        try {
            $manager->renew($invitation, $issuer, 7);
            $this->fail('Renewing an expired link must not create a duplicate active invitation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }
        $this->assertTrue($invitation->fresh()->isExpired());
    }
}
