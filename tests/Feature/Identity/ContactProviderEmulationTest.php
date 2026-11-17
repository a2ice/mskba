<?php

namespace Tests\Feature\Identity;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Vk\Domain\Models\VkAccount;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class ContactProviderEmulationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('themes.active', 'mskba_app');
        config()->set('app.url', 'http://localhost');
        config()->set('contact_emulation.enabled', true);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $user->createProfile([]);

        return $user;
    }

    private function url(): string
    {
        return route('account.contacts.provider-emulation');
    }

    public function test_provider_simulation_requires_authentication(): void
    {
        $this->postJson($this->url(), ['provider' => 'telegram', 'outcome' => 'success'])
            ->assertUnauthorized();
        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_emulation_is_not_available_without_explicit_flag_or_with_production_host(): void
    {
        $user = $this->user();
        config()->set('contact_emulation.enabled', false);

        $this->actingAs($user)->get(route('account.contacts'))->assertOk()
            ->assertDontSee('data-emulate-url', false);
        $this->postJson($this->url(), ['provider' => 'vk', 'outcome' => 'success'])
            ->assertNotFound();

        config()->set('contact_emulation.enabled', true);
        config()->set('app.url', 'https://mskba.ru');
        $this->postJson($this->url(), ['provider' => 'vk', 'outcome' => 'success'])
            ->assertNotFound();
        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_production_environment_rejects_emulation_even_for_allowed_dev_host(): void
    {
        $user = $this->user();
        config()->set('contact_emulation.enabled', true);
        config()->set('app.url', 'https://dev.mskba.ru');

        // CSRF is active outside testing; bypass only this middleware to reach
        // the emulator's independent production guard.
        $this->withoutMiddleware();
        $oldEnvironment = app()->environment();
        try {
            app()->detectEnvironment(static fn (): string => 'production');

            $this->actingAs($user)->postJson($this->url(), [
                'provider' => 'telegram', 'outcome' => 'success',
            ])->assertNotFound();
        } finally {
            app()->detectEnvironment(static fn (): string => $oldEnvironment);
        }

        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_confirmation_wizard_uses_the_same_emulator_when_enabled(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('account.confirmation'))->assertOk()
            ->assertSee('data-emulate-url', false)
            ->assertSee('id="confirmation-provider-emulation-outcome"', false)
            ->assertSee('class="select-control"', false)
            ->assertSee('<use href="#chevron-down"/>', false)
            ->assertSee('Тестовый режим')
            ->assertSee('value="telegram"', false)
            ->assertSee('value="vk"', false);
    }

    public function test_telegram_emulation_creates_a_verified_primary_with_test_namespace_only(): void
    {
        $user = $this->user();
        $this->actingAs($user)->postJson($this->url(), [
            'provider' => 'telegram', 'outcome' => 'success',
        ])->assertCreated()
            ->assertJsonPath('emulated', true)
            ->assertJsonPath('contact.type', 'telegram')
            ->assertJsonPath('contact.value', '@test_emulate_001')
            ->assertJsonPath('contact.verified', true)
            ->assertJsonPath('contact.primary', true);

        $contact = $user->contacts()->sole();
        $this->assertSame('test_emulate_'.$user->id.'_001', $contact->value);
        $this->assertSame('test_provider_emulation', $contact->meta['source']);
        $this->assertTrue($contact->meta['is_emulated']);
        $this->assertTrue($contact->hasBeenVerified());
        $this->assertTrue($contact->is_primary);
        $this->assertDatabaseCount('telegram_accounts', 0);
        $this->assertDatabaseCount('vk_accounts', 0);
        $this->actingAs($user)->get(route('account.contacts'))->assertOk()
            ->assertSee('Тестовый контакт')
            ->assertSee('@test_emulate_001');
    }

    public function test_vk_emulation_preserves_existing_verified_primary_and_allocates_new_test_ids(): void
    {
        $user = $this->user();
        $primary = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'primary@example.test',
            'is_primary' => true,
            'verified_at' => now(),
        ]);

        $this->actingAs($user)->postJson($this->url(), [
            'provider' => 'vk', 'outcome' => 'success',
        ])->assertCreated()
            ->assertJsonPath('contact.type', 'vk')
            ->assertJsonPath('contact.value', 'test_emulate_001')
            ->assertJsonPath('contact.primary', false);

        $this->postJson($this->url(), [
            'provider' => 'vk', 'outcome' => 'success',
        ])->assertCreated()
            ->assertJsonPath('contact.value', 'test_emulate_002')
            ->assertJsonPath('contact.primary', false);

        $this->assertTrue($primary->fresh()->is_primary);
        $this->assertCount(3, $user->contacts);
    }

    public function test_both_simulated_providers_can_be_linked_to_the_same_account(): void
    {
        $user = $this->user();
        $this->actingAs($user)->postJson($this->url(), [
            'provider' => 'telegram', 'outcome' => 'success',
        ])->assertCreated()->assertJsonPath('contact.value', '@test_emulate_001');

        $this->postJson($this->url(), [
            'provider' => 'vk', 'outcome' => 'success',
        ])->assertCreated()->assertJsonPath('contact.value', 'test_emulate_001');

        $contacts = $user->contacts()->get();
        $this->assertCount(2, $contacts);
        $this->assertSame(1, $contacts->where('is_primary', true)->count());
        $this->assertTrue($contacts->every(fn ($contact) => $contact->hasBeenVerified()));
    }

    public function test_simulated_failures_do_not_create_contacts(): void
    {
        $user = $this->user();
        $statuses = [
            'denied' => 403,
            'cancelled' => 422,
            'unavailable' => 502,
            'timeout' => 504,
            'conflict' => 409,
        ];

        foreach (['telegram', 'vk'] as $provider) {
            foreach ($statuses as $outcome => $status) {
                $this->actingAs($user)->postJson($this->url(), compact('provider', 'outcome'))
                    ->assertStatus($status)
                    ->assertJsonPath('emulated', true);
            }
        }
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_invalid_provider_or_outcome_is_rejected(): void
    {
        $user = $this->user();
        $this->actingAs($user)->postJson($this->url(), [
            'provider' => 'email', 'outcome' => 'success',
        ])->assertUnprocessable()->assertJsonValidationErrors('provider');

        $this->postJson($this->url(), [
            'provider' => 'vk', 'outcome' => 'custom_code',
        ])->assertUnprocessable()->assertJsonValidationErrors('outcome');
    }

    public function test_enabled_view_offers_both_providers_without_credentials(): void
    {
        config()->set('telegram.bot_username', '');
        config()->set('vk.app_id', '');
        $user = $this->user();

        $this->actingAs($user)->get(route('account.contacts'))->assertOk()
            ->assertSee('data-emulate-url', false)
            ->assertSee('class="select-control"', false)
            ->assertSee('<use href="#chevron-down"/>', false)
            ->assertSee('value="telegram"', false)
            ->assertSee('value="vk"', false)
            ->assertSee('Тестовый режим')
            ->assertSee('Успешная авторизация')
            ->assertSee('Пользователь отменил вход');
    }
}
