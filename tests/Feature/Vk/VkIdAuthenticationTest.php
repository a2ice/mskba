<?php

namespace Tests\Feature\Vk;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Enums\UserDuplicateEvidenceTypeEnum;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Vk\Application\DTO\VkUserIdentityDTO;
use App\Modules\Vk\Application\UseCases\ResolveVkUserHandler;
use App\Modules\Vk\Domain\Models\VkAccount;
use App\Modules\Vk\Infrastructure\Jobs\SyncVkProfileAvatarJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class VkIdAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        config([
            'vk.app_id' => '12345',
            'vk.redirect_uri' => 'https://mskba.test/auth/vk/callback',
            'vk.authorize_url' => 'https://id.vk.test/authorize',
            'vk.token_url' => 'https://id.vk.test/oauth2/auth',
            'vk.user_info_url' => 'https://id.vk.test/oauth2/user_info',
        ]);
    }

    public function test_start_creates_pkce_flow_and_safe_redirect(): void
    {
        $response = $this->get(route('auth.vk.start', ['redirect_to' => '/account']));

        $response->assertRedirectContains('https://id.vk.test/authorize?');
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('12345', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('s256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertArrayHasKey($query['state'], session('vk.oauth_flows'));
        $this->assertSame(url('/account'), session('vk.oauth_flows')[$query['state']]['redirect_url']);
    }

    public function test_callback_creates_user_and_logs_in(): void
    {
        $state = $this->startFlow();
        $this->fakeVk($state, '777');

        $this->get(route('auth.vk.callback', [
            'state' => $state,
            'code' => 'authorization-code',
            'device_id' => 'device-1',
        ]))->assertRedirect(url('/account'));

        $user = User::query()->where('username', 'vk_777')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(UserRegistrationChannelEnum::VK_ID, $user->registration_channel);
        $this->assertDatabaseHas('vk_accounts', ['user_id' => $user->id, 'vk_user_id' => '777']);
        $this->assertDatabaseHas('contacts', [
            'contactable_type' => 'user',
            'contactable_id' => $user->id,
            'type' => ContactTypeEnum::VK->value,
            'value' => '777',
        ]);
        $this->assertNotNull(Contact::query()->where('contactable_id', $user->id)->where('type', ContactTypeEnum::VK)->sole()->verified_at);
        $this->assertSame(1, UserNotification::query()
            ->where('user_id', $user->id)
            ->where('title', 'Контакт подтвержден')
            ->count());
        $this->assertSame('Иван', $user->profile?->first_name);
        $this->assertSame('Петров', $user->profile?->last_name);
        $this->assertSame(UserGenderEnum::MALE, $user->profile?->gender);
        $this->assertSame('1990-05-20', $user->profile?->birth_date?->format('Y-m-d'));
        Queue::assertPushed(fn (SyncVkProfileAvatarJob $job): bool => $job->vkAccountId === $user->vkAccount?->id);

        $this->get(route('account'))
            ->assertOk()
            ->assertSee('aria-label="Иван Петров"', false);
    }

    public function test_callback_reuses_existing_vk_identity_and_rejects_replayed_state(): void
    {
        $user = User::factory()->create(['username' => 'existing']);
        VkAccount::query()->create(['user_id' => $user->id, 'vk_user_id' => '777']);
        $state = $this->startFlow();
        $this->fakeVk($state, '777');
        $parameters = ['state' => $state, 'code' => 'code', 'device_id' => 'device'];

        $this->get(route('auth.vk.callback', $parameters))->assertRedirect(url('/account'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, Contact::query()
            ->where('contactable_id', $user->id)
            ->where('type', ContactTypeEnum::VK)
            ->where('value', '777')
            ->count());

        $this->post(route('auth.logout'));
        $this->get(route('auth.vk.callback', $parameters))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Сессия входа через VK ID истекла. Начните вход заново.');
    }

    public function test_repeated_vk_login_does_not_duplicate_contact_or_confirmation_notification(): void
    {
        $identity = new VkUserIdentityDTO(
            id: '777',
            firstName: 'Иван',
            lastName: 'Петров',
            avatarUrl: 'https://cdn.test/avatar.jpg',
            rawData: ['user_id' => '777'],
        );
        $handler = $this->app->make(ResolveVkUserHandler::class);

        $firstResult = $handler->handle($identity);
        $secondResult = $handler->handle($identity);
        $user = $firstResult['user'];

        $this->assertSame($user->id, $secondResult['user']->id);

        $this->assertSame(1, Contact::query()
            ->where('contactable_id', $user->id)
            ->where('type', ContactTypeEnum::VK)
            ->where('value', '777')
            ->count());
        $this->assertSame(1, UserNotification::query()
            ->where('user_id', $user->id)
            ->where('title', 'Контакт подтвержден')
            ->count());
    }

    public function test_repeated_vk_login_does_not_overwrite_manually_edited_profile(): void
    {
        $user = User::factory()->create(['username' => 'manual-profile']);
        $profile = $user->createProfile([
            'first_name' => 'Ручное имя',
            'last_name' => 'Ручная фамилия',
            'gender' => UserGenderEnum::FEMALE,
            'birth_date' => '1988-01-02',
        ]);
        VkAccount::query()->create(['user_id' => $user->id, 'vk_user_id' => '777']);

        $this->app->make(ResolveVkUserHandler::class)->handle(new VkUserIdentityDTO(
            id: '777',
            firstName: 'Иван',
            lastName: 'Петров',
            avatarUrl: 'https://cdn.test/avatar.jpg',
            rawData: ['user_id' => '777'],
            gender: 'male',
            birthDate: '1990-05-20',
        ));

        $profile->refresh();
        $this->assertSame('Ручное имя', $profile->first_name);
        $this->assertSame('Ручная фамилия', $profile->last_name);
        $this->assertSame(UserGenderEnum::FEMALE, $profile->gender);
        $this->assertSame('1988-01-02', $profile->birth_date?->format('Y-m-d'));
    }

    public function test_partial_vk_birthday_is_ignored(): void
    {
        $state = $this->startFlow();
        $this->fakeVk($state, '778', birthday: '20.05');

        $this->get(route('auth.vk.callback', [
            'state' => $state,
            'code' => 'authorization-code',
            'device_id' => 'device-1',
        ]))->assertRedirect(url('/account'));

        $user = User::query()->where('username', 'vk_778')->firstOrFail();
        $this->assertNull($user->profile?->birth_date);
    }

    public function test_blocked_vk_user_is_not_authenticated(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::BLOCKED]);
        VkAccount::query()->create(['user_id' => $user->id, 'vk_user_id' => '777']);
        $state = $this->startFlow();
        $this->fakeVk($state, '777');

        $this->get(route('auth.vk.callback', ['state' => $state, 'code' => 'code', 'device_id' => 'device']))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Аккаунт заблокирован. Обратитесь в поддержку.');
        $this->assertGuest();
    }

    public function test_callback_rejects_unknown_state_without_calling_vk(): void
    {
        Http::fake();

        $this->get(route('auth.vk.callback', [
            'state' => 'unknown-state',
            'code' => 'code',
            'device_id' => 'device',
        ]))->assertRedirect(route('login'))->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_vk_identity_attached_to_alias_logs_in_canonical_user(): void
    {
        $canonical = User::factory()->create();
        $alias = User::factory()->create();
        $alias->forceFill(['canonical_user_id' => $canonical->id])->save();
        VkAccount::query()->create(['user_id' => $alias->id, 'vk_user_id' => '777']);
        $state = $this->startFlow();
        $this->fakeVk($state, '777');

        $this->get(route('auth.vk.callback', ['state' => $state, 'code' => 'code', 'device_id' => 'device']))
            ->assertRedirect(url('/account'));

        $this->assertAuthenticatedAs($canonical);
        $this->assertDatabaseHas('contacts', [
            'contactable_type' => 'user',
            'contactable_id' => $canonical->id,
            'type' => ContactTypeEnum::VK->value,
            'value' => '777',
        ]);
        $this->assertDatabaseMissing('contacts', [
            'contactable_type' => 'user',
            'contactable_id' => $alias->id,
            'type' => ContactTypeEnum::VK->value,
            'value' => '777',
        ]);
    }

    public function test_disabled_vk_configuration_hides_button_and_returns_controlled_error(): void
    {
        config(['vk.app_id' => null]);

        $this->get(route('login'))->assertOk()->assertDontSee('Войти через VK ID');
        $this->get(route('auth.vk.start'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Вход через VK ID сейчас недоступен.');
    }

    public function test_linking_vk_owned_by_another_user_creates_duplicate_without_reassigning_it(): void
    {
        $current = User::factory()->create();
        $owner = User::factory()->create();
        VkAccount::query()->create(['user_id' => $owner->id, 'vk_user_id' => '777']);
        $this->actingAs($current);
        $state = $this->startFlow(route('account.vk.link'));
        $this->fakeVk($state, '777');

        $this->get(route('auth.vk.callback', ['state' => $state, 'code' => 'code', 'device_id' => 'device']))
            ->assertRedirect(route('account.contacts'))
            ->assertSessionHas('warning');

        $this->assertSame($owner->id, VkAccount::query()->where('vk_user_id', '777')->sole()->user_id);
        $this->assertDatabaseHas('user_duplicate_evidence', [
            'type' => UserDuplicateEvidenceTypeEnum::VK_IDENTITY->value,
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('contacts', [
            'contactable_type' => 'user',
            'contactable_id' => $current->id,
            'type' => ContactTypeEnum::VK->value,
            'value' => '777',
        ]);
    }

    public function test_linking_available_vk_identity_creates_verified_contact(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $state = $this->startFlow(route('account.vk.link'));
        $this->fakeVk($state, '777');

        $this->get(route('auth.vk.callback', ['state' => $state, 'code' => 'code', 'device_id' => 'device']))
            ->assertRedirect(route('account.contacts'))
            ->assertSessionHas('success');

        $contact = Contact::query()
            ->where('contactable_type', 'user')
            ->where('contactable_id', $user->id)
            ->where('type', ContactTypeEnum::VK)
            ->where('value', '777')
            ->sole();

        $this->assertNotNull($contact->verified_at);
        $this->assertSame('Иван Петров', $contact->displayValue());
        $this->assertSame(1, UserNotification::query()
            ->where('user_id', $user->id)
            ->where('title', 'Контакт подтвержден')
            ->count());
    }

    private function startFlow(?string $url = null): string
    {
        $this->get($url ?? route('auth.vk.start', ['redirect_to' => '/account']))->assertRedirect();
        $flows = session('vk.oauth_flows');

        return (string) array_key_first($flows);
    }

    private function fakeVk(string $state, string $userId, string $birthday = '1990-05-20'): void
    {
        Http::fake([
            'id.vk.test/oauth2/auth*' => Http::response([
                'access_token' => 'access-token',
                'user_id' => $userId,
                'state' => $state,
            ]),
            'id.vk.test/oauth2/user_info*' => Http::response([
                'user' => [
                    'user_id' => $userId,
                    'first_name' => 'Иван',
                    'last_name' => 'Петров',
                    'avatar' => 'https://example.test/avatar.jpg',
                    'sex' => 2,
                    'birthday' => $birthday,
                ],
            ]),
        ]);
    }
}
