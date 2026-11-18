<?php

namespace Tests\Feature\Invitation;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Invitation\Application\Services\AccountInvitationManager;
use App\Modules\Invitation\Domain\Models\AccountInvitation;
use App\Modules\Invitation\Presentation\Mail\AccountInvitationMail;
use App\Modules\Venue\Application\UseCases\SubmitVenueOwnershipClaimHandler;
use App\Modules\Venue\Application\UseCases\ReviewVenueOwnershipClaimHandler;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class AccountInvitationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('mail.default', 'smtp');
        config()->set('features.venue_rental.rental_flow', true);
        Mail::fake();
    }

    private function invite(string $email = 'invitee@example.test', string $role = 'owner'): array
    {
        $issuer = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $venue = Venue::factory()->create();
        [$invitation, $url] = app(AccountInvitationManager::class)->create(
            $issuer, $email, 'venue', $venue->id, $role, 'Приглашение в зал',
            'Присоединяйтесь к команде площадки.', 7,
        );
        $token = basename(parse_url($url, PHP_URL_PATH));
        return [$issuer, $venue, $invitation, $token];
    }

    public function test_view_only_tracks_open_and_never_verifies_contact(): void
    {
        [, , $invitation, $token] = $this->invite();
        Mail::assertSent(AccountInvitationMail::class);
        $this->followingRedirects()->get(route('account-invitations.show', $token))->assertOk()
            ->assertSee('Приглашение в зал');

        $invitation->refresh();
        $this->assertSame('viewed', $invitation->progress);
        $this->assertNull($invitation->accepted_user_id);
        $this->assertNull($invitation->contact_verified_at);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertSame(1, $invitation->views_count);
        $this->assertNotSame($token, $invitation->token_hash);
    }

    public function test_registration_explicitly_verifies_invited_email_but_does_not_grant_ownership(): void
    {
        [$issuer, $venue, $invitation, $token] = $this->invite();

        $this->get(route('account-invitations.show', $token))->assertRedirect(route('account-invitations.continue'));
        $this->post(route('account-invitations.register'), [
            'username' => 'venue_invitee',
            'password' => 'SafePass#2026',
            'password_confirmation' => 'SafePass#2026',
            'privacy_consent' => '1',
            'accept_invitation' => '1',
        ])->assertRedirect(route('account-invitations.continue'));

        $invitation->refresh();
        $user = User::findOrFail($invitation->accepted_user_id);
        $this->assertSame(UserStatusEnum::UNCONFIRMED, $user->status);
        $this->assertSame('awaiting_ownership', $invitation->progress);
        $this->assertNotNull($invitation->contact_verified_at);
        $this->assertNotNull($invitation->registered_at);
        $this->assertDatabaseHas('contacts', [
            'contactable_type' => 'user',
            'contactable_id' => $user->id,
            'type' => ContactTypeEnum::EMAIL->value,
            'value' => 'invitee@example.test',
            'is_primary' => true,
        ]);
        $this->assertTrue($user->hasVerifiedPrimaryContact());
        $this->assertSame(0, ContractMembership::query()
            ->where('scope_type', 'venue')->where('scope_id', $venue->id)
            ->where('access_level', 'owner')->count());

        // Verified primary contact is enough to start the existing
        // independently moderated ownership-claim workflow.
        $claim = app(SubmitVenueOwnershipClaimHandler::class)->handle(
            $venue, $user, 'Я уполномоченный представитель площадки и подтверждаю владение.',
        );
        $this->assertSame($user->id, $claim->applicant_user_id);
        app(ReviewVenueOwnershipClaimHandler::class)->approve($claim, $issuer, 'Документы проверены');
        $this->assertSame('completed', $invitation->fresh()->status);
    }

    public function test_an_existing_user_must_explicitly_accept_before_email_is_verified(): void
    {
        [, , $invitation, $token] = $this->invite('existing@example.test');
        $user = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        $this->actingAs($user)->followingRedirects()->get(route('account-invitations.show', $token))->assertOk();
        $this->assertFalse($user->hasVerifiedPrimaryContact());

        $this->post(route('account-invitations.accept'), [
            'accept_invitation' => '1',
        ])->assertRedirect(route('account-invitations.continue'));

        $this->assertTrue($user->hasVerifiedPrimaryContact());
        $this->assertSame($user->id, $invitation->fresh()->accepted_user_id);
    }

    public function test_second_identity_cannot_accept_claimed_link(): void
    {
        [, , $invitation, $token] = $this->invite();
        $one = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        $two = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        $this->get(route('account-invitations.show', $token));
        $this->actingAs($one)->post(route('account-invitations.accept'), [
            'accept_invitation' => '1',
        ])->assertRedirect();
        $this->actingAs($two)->post(route('account-invitations.accept'), [
            'accept_invitation' => '1',
        ])->assertForbidden();
        $this->assertSame($one->id, $invitation->fresh()->accepted_user_id);
        $this->assertFalse($two->hasVerifiedPrimaryContact());
    }

    public function test_link_rotation_invalidates_old_url(): void
    {
        [$issuer, , $invitation, $oldToken] = $this->invite();
        $newUrl = app(AccountInvitationManager::class)->renew($invitation, $issuer);
        $newToken = basename(parse_url($newUrl, PHP_URL_PATH));
        $this->assertNotSame($oldToken, $newToken);
        $this->get(route('account-invitations.show', $oldToken))->assertNotFound();
        $this->followingRedirects()->get(route('account-invitations.show', $newToken))->assertOk();
    }

    public function test_employee_role_waits_for_account_confirmation(): void
    {
        [, $venue, $invitation, $token] = $this->invite('staff@example.test', 'staff');
        $user = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);

        $this->get(route('account-invitations.show', $token));
        $this->actingAs($user)->post(route('account-invitations.accept'), [
            'accept_invitation' => '1',
        ])->assertRedirect();

        $this->assertSame('awaiting_account', $invitation->fresh()->progress);
        $this->assertDatabaseMissing('contract_memberships', [
            'scope_type' => 'venue', 'scope_id' => $venue->id, 'user_id' => $user->id,
        ]);

        $user->createProfile([
            'first_name' => 'Иван', 'last_name' => 'Сотрудник',
            'birth_date' => '1990-01-01', 'gender' => 'male',
        ]);
        $user->confirmAccount();
        $this->followingRedirects()->get(route('account-invitations.show', $token))->assertOk();
        $this->assertSame('completed', $invitation->fresh()->status);
        $this->assertDatabaseHas('contract_memberships', [
            'scope_type' => 'venue', 'scope_id' => $venue->id,
            'user_id' => $user->id, 'access_level' => 'staff',
        ]);
    }
    public function test_expired_and_revoked_links_cannot_verify_contact(): void
    {
        [$issuer, , $invitation, $token] = $this->invite();
        $user = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        $this->get(route('account-invitations.show', $token))->assertRedirect();
        $invitation->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->actingAs($user)->post(route('account-invitations.accept'), [
            'accept_invitation' => '1',
        ])->assertStatus(410);
        $this->assertFalse($user->hasVerifiedPrimaryContact());

        $newUrl = app(AccountInvitationManager::class)->renew($invitation, $issuer);
        $this->get($newUrl)->assertRedirect();
        app(AccountInvitationManager::class)->revoke($invitation, $issuer);
        $this->post(route('account-invitations.accept'), [
            'accept_invitation' => '1',
        ])->assertStatus(410);
        $this->assertFalse($user->hasVerifiedPrimaryContact());
    }

}
