<?php

namespace Tests\Feature\Identity;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationChannelEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CreationOperationalPermissionResumeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantCreationPermissionsToTestActors = false;

    public function test_confirmed_user_returns_to_event_wizard_after_verifying_email_on_contacts_page(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $this->actingAs($user)
            ->get(route('events.wizard', ['type' => 'training']))
            ->assertRedirect(route('account.contacts'))
            ->assertSessionHas('operational_permission_intent.return_url', route('events.wizard', ['type' => 'training'], false));

        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'resume-from-contacts@example.test',
            'is_primary' => true,
            'is_public' => false,
        ]);
        $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => bcrypt('123456'),
            'sent_to' => 'resume-from-contacts@example.test',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->actingAs($user)
            ->post(route('account.contacts.verification.confirm', $contact), [
                'code' => '123456',
            ])
            ->assertRedirect(route('events.wizard', ['type' => 'training'], false))
            ->assertSessionHas('status')
            ->assertSessionMissing('operational_permission_intent');

        $checker = app(UserOperationalPermissionChecker::class);
        $this->assertTrue($checker->allows($user, UserOperationalPermissionEnum::CREATE_EVENT));
        $this->assertTrue($checker->allows($user, UserOperationalPermissionEnum::CREATE_TOURNAMENT));
    }
}
