<?php

namespace Tests\Feature\Identity;

use App\Modules\Contact\Application\UseCases\SyncVerifiedTelegramContactHandler;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationChannelEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class AccountContactsDialogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $user->createProfile([]);

        return $user;
    }

    public function test_contacts_page_has_modal_channels_and_no_manual_social_username_fields(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get(route('account.contacts'))->assertOk()
            ->assertSee('data-contact-add-open', false)
            ->assertSee('data-contact-add-step="method"', false)
            ->assertSee('data-contact-add-step="value"', false)
            ->assertSee('data-contact-add-step="telegram"', false)
            ->assertSee('data-telegram-start-url', false)
            ->assertSee('data-bot-link', false)
            ->assertSee('data-contact-add-step="vk"', false)
            ->assertSee('data-contact-add-step="done"', false)
            ->assertSee('data-account-contacts-list', false)
            ->assertSee('SMS-подтверждение пока недоступно')
            ->assertDontSee('Открыть подтверждение аккаунта')
            ->assertDontSee('id="new-contact-type"', false);
    }

    public function test_ajax_email_and_phone_are_added_unverified_and_return_json(): void
    {
        $user = $this->user();
        $this->actingAs($user)->postJson(route('account.contacts.store'), [
            'type' => 'email', 'value' => ' PERSON@example.test ',
        ])->assertCreated()->assertJsonPath('contact.type', 'email')
            ->assertJsonPath('contact.is_verified', false);

        $this->postJson(route('account.contacts.store'), [
            'type' => 'phone', 'value' => '+7 (999) 123-45-67',
        ])->assertCreated()->assertJsonPath('contact.type', 'phone')
            ->assertJsonPath('contact.is_verified', false);

        $this->assertDatabaseHas('contacts', [
            'contactable_type' => 'user', 'contactable_id' => $user->id,
            'type' => 'email', 'value' => 'person@example.test', 'verified_at' => null,
        ]);
        $this->assertDatabaseHas('contacts', [
            'contactable_type' => 'user', 'contactable_id' => $user->id,
            'type' => 'phone', 'value' => '+79991234567', 'verified_at' => null,
        ]);

        $this->get(route('account.contacts'))->assertOk()
            ->assertSee('person@example.test')->assertSee('+79991234567');
    }

    public function test_ajax_rejects_invalid_and_duplicate_emails_and_manual_social_identities(): void
    {
        $owner = $this->user();
        $requester = $this->user();
        $owner->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'taken@example.test',
            'is_primary' => true,
        ]);

        $this->actingAs($requester)->postJson(route('account.contacts.store'), [
            'type' => 'email', 'value' => 'invalid-address',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->postJson(route('account.contacts.store'), [
            'type' => 'email', 'value' => 'taken@example.test',
        ])->assertUnprocessable()->assertJsonPath('message', 'Этот email уже используется другим пользователем.');
        $this->postJson(route('account.contacts.store'), [
            'type' => 'phone', 'value' => '123',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');

        foreach (['telegram' => '@someusername', 'vk' => 'someuser'] as $type => $value) {
            $this->postJson(route('account.contacts.store'), [
                'type' => $type, 'value' => $value,
            ])->assertUnprocessable();
        }
        $this->assertSame(0, $requester->contacts()->count());
    }

    public function test_first_verified_email_becomes_primary_even_if_another_unverified_email_was_first(): void
    {
        $user = $this->user();
        $first = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL, 'value' => 'first@example.test',
            'is_primary' => true,
        ]);
        $second = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL, 'value' => 'second@example.test',
            'is_primary' => false,
        ]);
        $second->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => bcrypt('123456'), 'sent_to' => 'second@example.test',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->actingAs($user)->post(route('account.contacts.verification.confirm', $second), [
            'code' => '123456',
        ])->assertRedirect(route('account.contacts'));

        $this->assertFalse((bool) $first->fresh()->is_primary);
        $this->assertTrue((bool) $second->fresh()->is_primary);
        $this->assertNotNull($second->fresh()->verified_at);
        $this->assertTrue($user->hasVerifiedPrimaryContact());
        $this->patch(route('account.contacts.primary', $first))
            ->assertSessionHasErrors('contact');
        $this->assertTrue((bool) $second->fresh()->is_primary);
    }

    public function test_unverified_provisional_primary_can_be_deleted_but_verified_primary_cannot(): void
    {
        $user = $this->user();
        $provisional = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'provisional@example.test', 'is_primary' => true,
        ]);
        $this->actingAs($user)->delete(route('account.contacts.destroy', $provisional))
            ->assertRedirect(route('account.contacts'));
        $this->assertSoftDeleted('contacts', ['id' => $provisional->id]);

        $verified = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'verified@example.test', 'is_primary' => true,
            'verified_at' => now(),
        ]);
        $this->delete(route('account.contacts.destroy', $verified))
            ->assertRedirect(route('account.contacts'));
        $this->assertDatabaseHas('contacts', ['id' => $verified->id, 'deleted_at' => null]);
    }

    public function test_second_verified_email_does_not_override_existing_verified_primary(): void
    {
        $user = $this->user();
        $first = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL, 'value' => 'first-verified@example.test',
            'is_primary' => true, 'verified_at' => now(),
        ]);
        $second = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL, 'value' => 'second-verified@example.test',
            'is_primary' => false,
        ]);
        $second->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => bcrypt('654321'), 'sent_to' => 'second-verified@example.test',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->actingAs($user)->post(route('account.contacts.verification.confirm', $second), [
            'code' => '654321',
        ])->assertRedirect(route('account.contacts'));
        $this->assertTrue((bool) $first->fresh()->is_primary);
        $this->assertFalse((bool) $second->fresh()->is_primary);
    }

    public function test_verified_telegram_becomes_primary_when_other_contacts_are_unverified(): void
    {
        $user = $this->user();
        $email = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'waiting@example.test', 'is_primary' => true,
        ]);

        $contact = app(SyncVerifiedTelegramContactHandler::class)->handle(
            $user, 123456789, 'test_mskba', 'Test', 'User',
        );
        $this->assertNotNull($contact->verified_at);
        $this->assertTrue((bool) $contact->fresh()->is_primary);
        $this->assertFalse((bool) $email->fresh()->is_primary);
        $this->assertTrue($user->hasVerifiedPrimaryContact());
    }
}
