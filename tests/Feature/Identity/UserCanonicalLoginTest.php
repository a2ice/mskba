<?php

namespace Tests\Feature\Identity;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Application\UseCases\SetUserPasswordHandler;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserCanonicalLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_verified_phone_on_aliases_of_same_identity_accepts_matching_alias_password(): void
    {
        $canonical = User::factory()->create([
            'username' => 'canonical_contact_user',
            'password' => 'canonical-password',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $alias = User::factory()->create([
            'username' => 'alias_contact_user',
            'password' => 'alias-password',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $alias->forceFill(['canonical_user_id' => $canonical->id])->save();

        foreach ([$canonical, $alias] as $user) {
            Contact::query()->create([
                'contactable_type' => 'user',
                'contactable_id' => $user->id,
                'type' => ContactTypeEnum::PHONE,
                'value' => '+79990001122',
                'verified_at' => now(),
            ]);
        }

        $this->post(route('auth.login'), [
            'login' => '+79990001122',
            'password' => 'alias-password',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($canonical);
    }

    public function test_shared_verified_phone_across_different_canonical_identities_remains_ambiguous(): void
    {
        $canonical = User::factory()->create([
            'username' => 'canonical_contact_user',
            'password' => 'canonical-password',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $alias = User::factory()->create([
            'username' => 'alias_contact_user',
            'password' => 'alias-password',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $alias->forceFill(['canonical_user_id' => $canonical->id])->save();
        $other = User::factory()->create([
            'username' => 'other_contact_user',
            'password' => 'other-password',
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        foreach ([$canonical, $alias, $other] as $user) {
            Contact::query()->create([
                'contactable_type' => 'user',
                'contactable_id' => $user->id,
                'type' => ContactTypeEnum::PHONE,
                'value' => '+79990002233',
                'verified_at' => now(),
            ]);
        }

        $this->post(route('auth.login'), [
            'login' => '+79990002233',
            'password' => 'canonical-password',
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_password_rotation_invalidates_old_alias_password_and_keeps_alias_login_identifier(): void
    {
        $canonical = User::factory()->create([
            'username' => 'canonical_password_user',
            'password' => 'canonical-password',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $alias = User::factory()->create([
            'username' => 'alias_password_user',
            'password' => 'alias-password',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $alias->forceFill(['canonical_user_id' => $canonical->id])->save();

        app(SetUserPasswordHandler::class)->handle(
            $canonical,
            'alias-password',
            'RotatedPassword1!',
        );

        $this->assertNull($alias->fresh()->password);

        $this->post(route('auth.login'), [
            'login' => 'alias_password_user',
            'password' => 'alias-password',
        ])->assertSessionHasErrors('login');
        $this->assertGuest();

        $this->post(route('auth.login'), [
            'login' => 'alias_password_user',
            'password' => 'RotatedPassword1!',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($canonical->fresh());
    }
}
