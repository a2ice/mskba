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

    public function test_log_mailer_rejects_delivery_without_creating_a_pending_code(): void
    {
        config()->set('mail.default', 'log');
        $user = $this->user();
        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL, 'value' => 'unavailable@example.test',
        ]);
        $response = $this->actingAs($user)
            ->post(route('account.contacts.verification.store', $contact));
        $response->assertRedirect(route('account.contacts'))
            ->assertSessionHas('contactVerificationFeedbackContactId', $contact->id)
            ->assertSessionHas('error', 'Отправка писем на этом сервере пока не настроена. Попробуйте позже.');
        $this->assertDatabaseCount('contact_verifications', 0);

        $json = $this->postJson(route('account.confirmation.contacts.verification.store', $contact));
        $json->assertStatus(503)->assertJsonPath(
            'message', 'Отправка писем на этом сервере пока не настроена. Попробуйте позже.',
        );
        $this->assertDatabaseCount('contact_verifications', 0);
    }

    public function test_email_creation_returns_503_without_phantom_code_when_log_mailer_is_used(): void
    {
        config()->set('mail.default', 'log');
        $user = $this->user();
        $this->actingAs($user)->postJson(route('account.confirmation.contact.store'), [
            'type' => 'email', 'value' => 'just-added@example.test',
        ])->assertStatus(503)->assertJsonPath(
            'message', 'Отправка писем на этом сервере пока не настроена. Попробуйте позже.',
        );
        $this->assertDatabaseHas('contacts', ['value' => 'just-added@example.test']);
        $this->assertDatabaseCount('contact_verifications', 0);
    }

    public function test_failed_smtp_delivery_keeps_existing_code_and_does_not_leak_transport_error(): void
    {
        config()->set('mail.default', 'smtp');
        $user = $this->user();
        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL, 'value' => 'smtp-failure@example.test',
        ]);
        $previous = $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => bcrypt('123456'),
            'sent_to' => 'smtp-failure@example.test',
            'expires_at' => now()->addMinutes(10),
        ]);
        $previous->forceFill(['created_at' => now()->subSeconds(61)])->save();

        $pendingMail = \Mockery::mock();
        $pendingMail->shouldReceive('send')->once()->andThrow(new \RuntimeException('SECRET SMTP FAILURE'));
        \Illuminate\Support\Facades\Mail::shouldReceive('to')
            ->once()->with('smtp-failure@example.test')->andReturn($pendingMail);

        $this->actingAs($user)->postJson(route('account.confirmation.contacts.verification.store', $contact))
            ->assertStatus(503)
            ->assertJsonPath('message', 'Не удалось отправить код на почту. Попробуйте позже.')
            ->assertDontSee('SECRET SMTP FAILURE');

        $this->assertDatabaseCount('contact_verifications', 1);
        $this->assertDatabaseHas('contact_verifications', [
            'id' => $previous->id,
            'status' => ContactVerificationStatusEnum::PENDING->value,
        ]);
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
            ->assertDontSee('Первый подтверждённый контакт автоматически становится основным')
            ->assertDontSee('Остальные подтверждённые контакты можно назначать основными вручную')
            ->assertSee('SMS-подтверждение пока недоступно')
            ->assertDontSee('Открыть подтверждение аккаунта')
            ->assertDontSee('id="new-contact-type"', false);
    }

    public function test_contacts_page_renders_one_shared_delete_confirmation_dialog(): void
    {
        $user = $this->user();
        $first = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL, 'value' => 'first-to-delete@example.test',
        ]);
        $second = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL, 'value' => 'second-to-delete@example.test',
        ]);

        $html = $this->actingAs($user)->get(route('account.contacts'))
            ->assertOk()
            ->assertSee('Вы уверены, что хотите удалить контакт?')
            ->assertSee('data-contact-delete-dialog', false)
            ->assertSee('data-contact-delete-confirm', false)
            ->assertSee('data-contact-delete-cancel', false)
            ->assertSee('button danger', false)
            ->assertSee('data-contact-label="Email: first-to-delete@example.test"', false)
            ->assertSee('data-contact-label="Email: second-to-delete@example.test"', false)
            ->assertSee(route('account.contacts.destroy', $first), false)
            ->assertSee(route('account.contacts.destroy', $second), false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'data-contact-delete-dialog'));
        $this->assertSame(2, substr_count($html, 'data-contact-delete-form'));
        $this->assertDatabaseCount('contacts', 2);
    }

    public function test_unverified_email_without_code_offers_text_confirmation_button(): void
    {
        $user = $this->user();
        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL, 'value' => 'no-code@example.test',
        ]);

        $this->actingAs($user)->get(route('account.contacts'))->assertOk()
            ->assertSee('data-account-contacts-list-url=', false)
            ->assertSee('data-contact-resend-form data-cooldown-seconds="0"', false)
            ->assertSee('data-contact-resend-text', false)
            ->assertSee('>Подтвердить</span>', false)
            ->assertSee('data-contact-resend-error', false)
            ->assertDontSee('class="app-account-contacts__verify"', false)
            ->assertDontSee('data-tooltip="Отправить новый код"', false)
            ->assertSee(route('account.contacts.verification.store', $contact), false);
    }

    public function test_ajax_verification_start_sends_code_without_redirect_and_returns_server_cooldown(): void
    {
        config()->set('mail.default', 'smtp');
        \Illuminate\Support\Facades\Mail::fake();

        // Pin the application clock: separate HTTP requests must not
        // consume a real-world second and turn the expected 60 into 59.
        $this->travelTo(now()->startOfSecond());

        $user = $this->user();
        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL, 'value' => 'ajax-code@example.test',
        ]);

        $url = route('account.contacts.verification.store', $contact);
        $this->actingAs($user)->postJson($url)->assertOk()
            ->assertJsonPath('message', 'Код подтверждения отправлен.')
            ->assertJsonPath('seconds_left', 60);

        $this->assertDatabaseHas('contact_verifications', [
            'contact_id' => $contact->id,
            'status' => ContactVerificationStatusEnum::PENDING->value,
        ]);
        $this->get(route('account.contacts'))->assertOk()
            ->assertSee('class="app-account-contacts__verify"', false)
            ->assertSee('data-cooldown-seconds="60"', false)
            ->assertDontSee('data-contact-resend-text', false);

        $this->postJson($url)->assertStatus(429)
            ->assertJsonPath('seconds_left', 60);
    }

    public function test_ajax_email_start_failure_returns_json_without_page_redirect_or_phantom_code(): void
    {
        config()->set('mail.default', 'log');
        $user = $this->user();
        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL, 'value' => 'invalid-mailer@example.test',
        ]);

        $this->actingAs($user)->postJson(route('account.contacts.verification.store', $contact))
            ->assertStatus(503)
            ->assertJsonPath('message', 'Отправка писем на этом сервере пока не настроена. Попробуйте позже.');
        $this->assertDatabaseCount('contact_verifications', 0);
    }

    public function test_pending_email_is_shown_in_one_compact_contact_row_with_accessible_icon_actions(): void
    {
        $user = $this->user();
        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'pending@example.test',
            'is_primary' => true,
        ]);
        $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => bcrypt('123456'),
            'sent_to' => 'pending@example.test',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->actingAs($user)->get(route('account.contacts'))->assertOk()
            ->assertSee('class="app-account-contacts__item"', false)
            ->assertSee('class="app-account-contacts__controls"', false)
            ->assertSee('class="app-account-contacts__verify"', false)
            ->assertSee('aria-label="Шестизначный код из письма для pending@example.test"', false)
            ->assertSee('data-tooltip="Подтвердить код"', false)
            ->assertSee('data-tooltip="Ожидайте"', false)
            ->assertSee('data-contact-resend-timer', false)
            ->assertDontSee('Код действителен ещё')
            ->assertSee('data-tooltip="Удалить контакт"', false)
            ->assertSee('<use href="#refresh"/>', false)
            ->assertSee('<use href="#close"/>', false)
            ->assertSee(route('account.contacts.verification.confirm', $contact), false)
            ->assertSee(route('account.contacts.verification.store', $contact), false)
            ->assertSee(route('account.contacts.destroy', $contact), false)
            ->assertDontSee('class="app-account-contacts__actions"', false)
            ->assertDontSee('>Отправить новый код</button>', false)
            ->assertDontSee('>Удалить</button>', false);
    }

    public function test_cooldown_message_is_scoped_to_its_contact_and_has_real_seconds(): void
    {
        config()->set('mail.default', 'smtp');
        \Illuminate\Support\Facades\Mail::fake();
        $user = $this->user();
        $first = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL, 'value' => 'first@example.test',
            'is_primary' => true,
        ]);
        $other = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL, 'value' => 'other@example.test',
        ]);
        $this->actingAs($user)->post(route('account.contacts.verification.store', $first))
            ->assertRedirect(route('account.contacts'));

        $retry = $this->post(route('account.contacts.verification.store', $first));
        $retry->assertRedirect(route('account.contacts'))
            ->assertSessionHas('contactVerificationFeedbackContactId', $first->id)
            ->assertSessionHas('info', fn ($text) => str_contains($text, 'через ')
                && ! str_contains($text, ':seconds'));

        $page = $this->get(route('account.contacts'))->assertOk()
            ->assertSee('app-account-contacts__feedback', false)
            ->assertDontSee(':seconds');
        $html = $page->getContent();
        $firstRow = strpos($html, 'first@example.test');
        $otherRow = strpos($html, 'other@example.test');
        $this->assertNotFalse($firstRow);
        $this->assertNotFalse($otherRow);
        $this->assertSame(1, substr_count($html, 'data-contact-resend-timer'));
        $this->assertStringNotContainsString('Код уже отправлен. Новый код можно запросить через', $html);
    }

    public function test_verification_errors_show_beneath_the_corresponding_code_field(): void
    {
        $user = $this->user();
        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'invalid-code@example.test', 'is_primary' => true,
        ]);
        $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => bcrypt('123456'),
            'sent_to' => 'invalid-code@example.test',
            'expires_at' => now()->addMinutes(10),
        ]);

        $post = route('account.contacts.verification.confirm', $contact);
        $this->actingAs($user)->post($post, [
            'code' => '999999', '_verification_contact_id' => $contact->id,
        ])->assertRedirect(route('account.contacts'))
            ->assertSessionHas('contactVerificationFeedbackContactId', $contact->id)
            ->assertSessionHas('error');

        $this->get(route('account.contacts'))->assertOk()
            ->assertSee('app-account-contacts__feedback--error', false)
            ->assertSee('Неверный код');

        $this->post($post, [
            'code' => '123', '_verification_contact_id' => $contact->id,
        ])->assertSessionHasErrors('code');

        // Invalid-length codes are also rejected by server-side FormRequest
        // validation (the browser normally stops them via the input pattern).
        $this->assertNotNull($contact->fresh());

    }

    public function test_new_email_code_expires_in_sixty_minutes(): void
    {
        $this->travelTo(now()->startOfSecond());
        config()->set('mail.default', 'smtp');
        \Illuminate\Support\Facades\Mail::fake();
        $user = $this->user();
        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL, 'value' => 'sixty-minutes@example.test',
        ]);
        $this->actingAs($user)->postJson(route('account.contacts.verification.store', $contact))->assertOk();
        $this->assertSame(
            3600.0,
            now()->diffInSeconds($contact->verifications()->latest()->firstOrFail()->expires_at, false),
        );
    }

    public function test_expired_email_code_restores_initial_confirmation_button(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = $this->user();
        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL, 'value' => 'expired-form@example.test', 'is_primary' => true,
        ]);
        $verification = $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => bcrypt('123456'),
            'sent_to' => 'expired-form@example.test',
            'expires_at' => now()->subSecond(),
        ]);
        $this->actingAs($user)->get(route('account.contacts'))->assertOk()
            ->assertSee('data-contact-resend-text', false)
            ->assertDontSee('data-contact-code-expires-at', false);
        $this->get(route('account.confirmation'))->assertOk()
            ->assertDontSee('Ввести код подтверждения');
        $this->assertSame(ContactVerificationStatusEnum::PENDING, $verification->fresh()->status);
    }

    public function test_two_pending_emails_have_independent_server_cooldowns(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = $this->user();
        foreach ([0, 42] as $index => $ageSeconds) {
            $email = $index === 0 ? 'first-timer@example.test' : 'second-timer@example.test';
            $contact = $user->contacts()->create([
                'type' => ContactTypeEnum::EMAIL,
                'value' => $email,
                'is_primary' => $index === 0,
            ]);
            $verification = $contact->verifications()->create([
                'channel' => ContactVerificationChannelEnum::EMAIL,
                'status' => ContactVerificationStatusEnum::PENDING,
                'code_hash' => bcrypt('123456'),
                'sent_to' => $email,
                'expires_at' => now()->addMinutes(15),
            ]);
            $verification->forceFill(['created_at' => now()->subSeconds($ageSeconds)])->save();
        }
        $html = $this->actingAs($user)->get(route('account.contacts'))->assertOk()->getContent();
        preg_match_all('/data-contact-resend-form data-cooldown-seconds="(\d+)"/', $html, $matches);
        $this->assertSame(['60', '18'], $matches[1]);
        $this->assertSame(2, substr_count($html, 'data-contact-resend-timer'));
        $this->travel(61)->seconds();
        $page = $this->get(route('account.contacts'))->assertOk();
        $page->assertSee('data-cooldown-seconds="0"', false);
    }

    public function test_confirmation_contact_panel_omits_duplicate_explanations(): void
    {
        $user = $this->user();
        $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'waiting@example.test',
            'is_primary' => true,
        ]);

        // No active code means the dialog must not offer stale code input.
        $this->actingAs($user)->get(route('account.confirmation'))->assertOk()
            ->assertDontSee('Ввести код подтверждения')
            ->assertSee('Добавить другой контакт')
            ->assertSee('Управлять контактами')
            ->assertDontSee('Достаточно одного подтверждённого')
            ->assertDontSee('После подтверждения первого контакта он автоматически становится основным.');
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

    public function test_verified_secondary_contact_has_clear_text_action_to_make_primary(): void
    {
        $user = $this->user();
        $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'primary-text@example.test',
            'is_primary' => true,
            'verified_at' => now(),
        ]);
        $secondary = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'secondary-text@example.test',
            'is_primary' => false,
            'verified_at' => now(),
        ]);

        $this->actingAs($user)->get(route('account.contacts'))->assertOk()
            ->assertSee(route('account.contacts.primary', $secondary), false)
            ->assertSee('class="button secondary app-account-contacts__primary-button"', false)
            ->assertSee('Сделать основным')
            ->assertDontSee('data-tooltip="Сделать основным"', false)
            ->assertDontSee('<use href="#check"/>', false);
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
