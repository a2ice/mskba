<?php

namespace Tests\Feature\Identity;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationChannelEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Application\Services\VerifiedContactOperationalPermissionGranter;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CreationOperationalPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantCreationPermissionsToTestActors = false;

    public function test_event_and_tournament_creation_permissions_are_denied_by_default(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        $checker = app(UserOperationalPermissionChecker::class);

        $this->assertFalse($checker->allows($user, UserOperationalPermissionEnum::CREATE_EVENT));
        $this->assertFalse($checker->allows($user, UserOperationalPermissionEnum::CREATE_TOURNAMENT));
        $this->assertTrue($checker->allows($user, UserOperationalPermissionEnum::CREATE_COORDINATION));
        $this->assertTrue($checker->allows($user, UserOperationalPermissionEnum::CREATE_TEAM));
    }

    public function test_creation_entrypoints_redirect_unverified_user_to_contact_confirmation(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);

        $this->actingAs($user)
            ->get(route('events.wizard', ['type' => 'game']))
            ->assertRedirect(route('account.confirmation'))
            ->assertSessionHas('operational_permission_intent.permission', UserOperationalPermissionEnum::CREATE_EVENT->value)
            ->assertSessionHas('operational_permission_intent.return_url', route('events.wizard', ['type' => 'game'], false))
            ->assertSessionHas('info');

        $this->actingAs($user)
            ->get(route('tournaments.create'))
            ->assertRedirect(route('account.confirmation'))
            ->assertSessionHas('operational_permission_intent.permission', UserOperationalPermissionEnum::CREATE_TOURNAMENT->value)
            ->assertSessionHas('operational_permission_intent.return_url', route('tournaments.create', absolute: false))
            ->assertSessionHas('info');
    }

    public function test_confirmed_user_without_verified_contact_is_sent_directly_to_contacts(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $this->actingAs($user)
            ->get(route('events.wizard', ['type' => 'training']))
            ->assertRedirect(route('account.contacts'))
            ->assertSessionHas('operational_permission_intent.permission', UserOperationalPermissionEnum::CREATE_EVENT->value)
            ->assertSessionHas('operational_permission_intent.return_url', route('events.wizard', ['type' => 'training'], false))
            ->assertSessionHas('info');
    }

    public function test_store_endpoints_are_also_protected_server_side(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);

        $this->actingAs($user)
            ->post(route('events.store'), [])
            ->assertRedirect(route('account.confirmation'));

        $this->actingAs($user)
            ->post(route('tournaments.store'), [])
            ->assertRedirect(route('account.confirmation'));
    }

    public function test_verified_contact_grants_missing_create_permissions(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'verified-permissions@example.test',
            'is_primary' => true,
            'is_public' => false,
            'verified_at' => now(),
        ]);

        app(VerifiedContactOperationalPermissionGranter::class)->grantMissing($user);

        $this->assertDatabaseHas('user_operational_permissions', [
            'user_id' => $user->id,
            'permission' => UserOperationalPermissionEnum::CREATE_EVENT->value,
            'is_allowed' => true,
        ]);
        $this->assertDatabaseHas('user_operational_permissions', [
            'user_id' => $user->id,
            'permission' => UserOperationalPermissionEnum::CREATE_TOURNAMENT->value,
            'is_allowed' => true,
        ]);
    }

    public function test_automatic_grant_never_overwrites_explicit_admin_denial(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'explicit-denial@example.test',
            'is_primary' => true,
            'is_public' => false,
            'verified_at' => now(),
        ]);
        UserOperationalPermission::query()->create([
            'user_id' => $user->id,
            'permission' => UserOperationalPermissionEnum::CREATE_EVENT,
            'is_allowed' => false,
        ]);

        app(VerifiedContactOperationalPermissionGranter::class)->grantMissing($user);

        $this->assertDatabaseHas('user_operational_permissions', [
            'user_id' => $user->id,
            'permission' => UserOperationalPermissionEnum::CREATE_EVENT->value,
            'is_allowed' => false,
        ]);
        $this->assertDatabaseHas('user_operational_permissions', [
            'user_id' => $user->id,
            'permission' => UserOperationalPermissionEnum::CREATE_TOURNAMENT->value,
            'is_allowed' => true,
        ]);
    }

    public function test_verified_user_with_explicit_denial_is_not_asked_to_verify_again(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'denied-but-verified@example.test',
            'is_primary' => true,
            'is_public' => false,
            'verified_at' => now(),
        ]);
        UserOperationalPermission::query()->create([
            'user_id' => $user->id,
            'permission' => UserOperationalPermissionEnum::CREATE_EVENT,
            'is_allowed' => false,
        ]);

        $this->actingAs($user)
            ->get(route('events.wizard', ['type' => 'game']))
            ->assertRedirect(route('account.contacts'))
            ->assertSessionHas('error')
            ->assertSessionMissing('operational_permission_intent');

        $this->assertDatabaseHas('user_operational_permissions', [
            'user_id' => $user->id,
            'permission' => UserOperationalPermissionEnum::CREATE_EVENT->value,
            'is_allowed' => false,
        ]);
    }

    public function test_contact_confirmation_grants_permissions_and_returns_to_intended_creation_flow(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        $user->createProfile([]);

        $this->actingAs($user)
            ->get(route('events.wizard', ['type' => 'game']))
            ->assertRedirect(route('account.confirmation'));

        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'resume-creation@example.test',
            'is_primary' => true,
            'is_public' => false,
        ]);
        $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => bcrypt('123456'),
            'sent_to' => 'resume-creation@example.test',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->actingAs($user)
            ->post(route('account.confirmation.contacts.verification.confirm', $contact), [
                'code' => '123456',
            ])
            ->assertRedirect(route('events.wizard', ['type' => 'game'], false))
            ->assertSessionHas('status');

        $checker = app(UserOperationalPermissionChecker::class);
        $this->assertTrue($checker->allows($user, UserOperationalPermissionEnum::CREATE_EVENT));
        $this->assertTrue($checker->allows($user, UserOperationalPermissionEnum::CREATE_TOURNAMENT));
    }
}
