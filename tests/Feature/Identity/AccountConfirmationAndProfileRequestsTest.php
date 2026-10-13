<?php

namespace Tests\Feature\Identity;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\ProfileChangeRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class AccountConfirmationAndProfileRequestsTest extends TestCase
{
    use RefreshDatabase;

    private string $oldTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->oldTheme = (string) config('themes.active');
        config()->set('themes.active', 'mskba_app');
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        app()->forgetInstance(ThemeResolver::class);
    }

    protected function tearDown(): void
    {
        config()->set('themes.active', $this->oldTheme);
        View::replaceNamespace('theme', resource_path('themes/'.$this->oldTheme.'/views'));
        app()->forgetInstance(ThemeResolver::class);
        parent::tearDown();
    }

    private function account(UserStatusEnum $status = UserStatusEnum::UNCONFIRMED, UserSystemRoleEnum $systemRole = UserSystemRoleEnum::USER): User
    {
        $user = User::factory()->create(['status' => $status, 'system_role' => $systemRole]);
        $user->createProfile([
            'first_name' => 'Мария', 'last_name' => 'Иванова',
            'birth_date' => '2001-05-20', 'gender' => UserGenderEnum::FEMALE,
        ]);

        return $user;
    }

    private function proposed(): array
    {
        return [
            'first_name' => 'Анна',
            'last_name' => 'Иванова',
            'birth_date' => '2001-05-20',
            'gender' => 'female',
            'reason' => 'Исправляю ошибку, допущенную при регистрации.',
        ];
    }

    public function test_confirm_account_without_any_participation_role_using_verified_telegram_contact(): void
    {
        $user = $this->account();
        $user->contacts()->create([
            'type' => ContactTypeEnum::TELEGRAM, 'value' => '889991',
            'is_primary' => true, 'verified_at' => now(),
        ]);

        $this->actingAs($user)->get(route('account'))->assertOk()
            ->assertSee('Статус пользователя')
            ->assertSee('Подтвердить аккаунт');

        $this->get(route('account.confirmation'))->assertOk()
            ->assertSee('Основной контакт')
            ->assertSee('Telegram')
            ->assertSee('Имя')
            ->assertSee('Фамилия')
            ->assertSee('Дата рождения')
            ->assertSee('Пол')
            ->assertDontSee('Выбери роль');

        $this->post(route('account.confirmation.complete'), [
            'first_name' => 'Мария', 'last_name' => 'Иванова',
            'birth_date' => '2001-05-20', 'gender' => 'female',
        ])->assertRedirect(route('account'));
        $this->assertSame(UserStatusEnum::CONFIRMED, $user->refresh()->status);
        $this->assertDatabaseMissing('user_participation_roles', ['user_id' => $user->id]);
    }

    public function test_confirm_requires_verified_primary_not_just_any_verified_contact_and_all_identity_fields(): void
    {
        $user = $this->account();
        $user->contacts()->create(['type' => ContactTypeEnum::EMAIL, 'value' => 'unverified@example.test', 'is_primary' => true]);
        $user->contacts()->create(['type' => ContactTypeEnum::TELEGRAM, 'value' => '889992',
            'is_primary' => false, 'verified_at' => now()]);

        $this->actingAs($user)->post(route('account.confirmation.complete'), [
            'first_name' => 'Мария', 'last_name' => 'Иванова',
            'birth_date' => '2001-05-20', 'gender' => 'female',
        ])->assertSessionHasErrors('contact');

        $this->assertSame(UserStatusEnum::UNCONFIRMED, $user->fresh()->status);
        $contact = $user->contacts()->where('type', ContactTypeEnum::EMAIL)->firstOrFail();
        $contact->forceFill(['verified_at' => now()])->save();
        $this->post(route('account.confirmation.complete'), [
            'first_name' => ' ', 'last_name' => 'Иванова',
            'birth_date' => '2001-05-20',
        ])->assertSessionHasErrors(['first_name', 'gender']);
        $this->assertSame(UserStatusEnum::UNCONFIRMED, $user->fresh()->status);
    }

    public function test_change_request_can_be_cancelled_resubmitted_and_approved_without_mutating_before_approval(): void
    {
        $admin = $this->account(UserStatusEnum::CONFIRMED, UserSystemRoleEnum::SUPERADMIN);
        $user = $this->account(UserStatusEnum::CONFIRMED);
        $this->actingAs($user)->post(route('account.profile-change-requests.store'), $this->proposed())
            ->assertRedirect(route('account.profile'));

        $change = ProfileChangeRequest::query()->firstOrFail();
        $this->assertSame('Мария', $user->profile->fresh()->first_name);
        $this->post(route('account.profile-change-requests.store'), $this->proposed())
            ->assertSessionHasErrors('reason');

        $this->post(route('account.profile-change-requests.cancel', $change))->assertRedirect(route('account.profile'));
        $this->assertSame(ProfileChangeRequest::CANCELLED, $change->fresh()->status);

        $this->post(route('account.profile-change-requests.store'), $this->proposed())
            ->assertRedirect(route('account.profile'));
        $pending = ProfileChangeRequest::query()->latest('id')->firstOrFail();
        $this->actingAs($admin)->post(route('admin.profile-change-requests.review', $pending), ['action' => 'approve'])
            ->assertRedirect(route('admin.profile-change-requests'));

        $this->assertSame(ProfileChangeRequest::APPROVED, $pending->fresh()->status);
        $this->assertSame('Анна', $user->profile->fresh()->first_name);
        $this->assertDatabaseHas('profile_change_request_events', ['profile_change_request_id' => $pending->id, 'action' => 'approve']);
    }

    public function test_reject_allows_retry_but_block_denies_retry_until_admin_unblocks(): void
    {
        $user = $this->account(UserStatusEnum::CONFIRMED);
        $admin = $this->account(UserStatusEnum::CONFIRMED, UserSystemRoleEnum::SUPERADMIN);

        $this->actingAs($user)->post(route('account.profile-change-requests.store'), $this->proposed())
            ->assertRedirect(route('account.profile'));
        $first = ProfileChangeRequest::query()->latest('id')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.profile-change-requests.review', $first), [
            'action' => 'reject', 'review_reason' => 'Недостаточно информации для изменения.',
        ])->assertRedirect(route('admin.profile-change-requests'));

        $this->actingAs($user)->post(route('account.profile-change-requests.store'), $this->proposed())
            ->assertRedirect(route('account.profile'));
        $second = ProfileChangeRequest::query()->latest('id')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.profile-change-requests.review', $second), [
            'action' => 'block', 'review_reason' => 'Повторяющиеся недостоверные запросы.',
        ])->assertRedirect(route('admin.profile-change-requests'));

        $this->actingAs($user)->post(route('account.profile-change-requests.store'), $this->proposed())
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)->post(route('admin.profile-change-requests.review', $second), ['action' => 'unblock'])
            ->assertRedirect(route('admin.profile-change-requests'));
        $this->assertSame(ProfileChangeRequest::REJECTED, $second->fresh()->status);
        $this->assertNotNull($second->fresh()->unblocked_at);

        $this->actingAs($user)->post(route('account.profile-change-requests.store'), $this->proposed())
            ->assertRedirect(route('account.profile'));
    }

    public function test_confirmed_account_cannot_set_unverified_contact_as_primary(): void
    {
        $user = $this->account(UserStatusEnum::CONFIRMED);
        $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'verified@example.test',
            'is_primary' => true,
            'verified_at' => now(),
        ]);
        $secondary = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'unverified2@example.test',
            'is_primary' => false,
        ]);

        $this->actingAs($user)->patch(route('account.contacts.primary', $secondary))
            ->assertSessionHasErrors('contact');

        $this->assertFalse($secondary->fresh()->is_primary);
        $this->assertTrue($user->contacts()->where('value', 'verified@example.test')->firstOrFail()->is_primary);
    }

    public function test_admin_queue_is_private_and_request_notifications_never_contain_proposed_profile_values(): void
    {
        $user = $this->account(UserStatusEnum::CONFIRMED);
        $admin = $this->account(UserStatusEnum::CONFIRMED, UserSystemRoleEnum::SUPERADMIN);
        $regular = $this->account(UserStatusEnum::CONFIRMED);

        $this->actingAs($regular)->get(route('admin.profile-change-requests'))->assertForbidden();

        $this->actingAs($user)->post(route('account.profile-change-requests.store'), $this->proposed())
            ->assertRedirect(route('account.profile'));
        $change = ProfileChangeRequest::query()->sole();

        $notification = \App\Modules\Notification\Domain\Models\UserNotification::query()
            ->where('user_id', $admin->id)->latest('id')->firstOrFail();

        $this->assertStringNotContainsString('Анна', $notification->body);
        $this->assertStringNotContainsString('2001-05-20', $notification->body);

        $this->actingAs($admin)->get(route('admin.profile-change-requests'))->assertOk()
            ->assertSee('Заявки на изменение профиля')
            ->assertSee($change->reason);

        $this->actingAs($regular)->post(route('admin.profile-change-requests.review', $change), [
            'action' => 'approve',
        ])->assertForbidden();

        $this->assertSame(ProfileChangeRequest::PENDING, $change->fresh()->status);
    }

    public function test_superadmin_cannot_review_own_profile_change_request(): void
    {
        $admin = $this->account(UserStatusEnum::CONFIRMED, UserSystemRoleEnum::SUPERADMIN);

        $this->actingAs($admin)->post(route('account.profile-change-requests.store'), $this->proposed())
            ->assertRedirect(route('account.profile'));
        $change = ProfileChangeRequest::query()->sole();

        $this->post(route('admin.profile-change-requests.review', $change), [
            'action' => 'approve',
        ])->assertForbidden();

        $this->assertSame(ProfileChangeRequest::PENDING, $change->fresh()->status);
    }
}
