<?php

namespace Tests\Feature;

use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class MskbaAppAccountProfileTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTheme = (string) config('themes.active');
        $this->switchTheme('mskba_app');
    }

    protected function tearDown(): void
    {
        $this->switchTheme($this->previousTheme);
        parent::tearDown();
    }

    private function switchTheme(string $theme): void
    {
        config()->set('themes.active', $theme);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/'.$theme.'/views'));
    }

    private function account(UserStatusEnum $status = UserStatusEnum::UNCONFIRMED): User
    {
        $user = User::factory()->create(['status' => $status]);
        $user->createProfile([
            'first_name' => 'Мария',
            'last_name' => 'Иванова',
            'middle_name' => 'Сергеевна',
            'birth_date' => '2001-05-20',
            'gender' => UserGenderEnum::FEMALE,
        ]);

        return $user;
    }

    public function test_profile_page_shows_public_nickname_embedded_avatar_upload_and_personal_fields(): void
    {
        $user = $this->account();
        $user->forceFill(['nickname' => 'mariq'])->save();

        $this->actingAs($user)->get(route('account.profile'))->assertOk()
            ->assertDontSee('Логин для входа')
            ->assertDontSee('id="app-profile-username"', false)
            ->assertSee('Публичный никнейм')
            ->assertSee('Никнейм может использоваться в ссылке на твою публичную страницу.')
            ->assertSee('value="mariq"', false)
            ->assertSee(route('users.show', ['user' => 'mariq']), false)
            ->assertSee(route('account.nickname.update'), false)
            ->assertSee(route('account.avatar.store'), false)
            ->assertSee('title="Загрузить аватар"', false)
            ->assertSee('for="app-profile-avatar-file"', false)
            ->assertSee('id="app-profile-avatar-file" name="avatar"', false)
            ->assertSee('class="app-profile__avatar-placeholder"', false)
            ->assertDontSee('class="app-profile__avatar-upload-icon"', false)
            ->assertSee('<use href="#user"/>', false)
            ->assertDontSee('class="button secondary app-profile__upload-control"', false)
            ->assertSee('Личные данные')
            ->assertSee('Дата рождения')
            ->assertSee('2001-05-20')
            ->assertSee('Мужской')
            ->assertSee('Женский')
            ->assertSee('name="first_name"', false)
            ->assertSee('name="last_name"', false)
            ->assertSee('name="gender"', false)
            ->assertSee('class="select-control"', false)
            ->assertSee('<use href="#chevron-down"/>', false)
            ->assertSee('name="birth_date"', false)
            ->assertDontSee('Рассчитывается автоматически по дате рождения.')
            ->assertDontSee('Возраст:')
            ->assertDontSee('data-profile-change-request-open', false);
    }

    public function test_overview_badge_opens_faq_while_actions_link_to_confirmation_page(): void
    {
        $user = $this->account();

        $this->actingAs($user)->get(route('account'))->assertOk()
            ->assertSee('data-account-confirmation-guide-open', false)
            ->assertSee('app-account-overview__confirmation-badge', false)
            ->assertSee('aria-haspopup="dialog"', false)
            ->assertSee('data-account-confirmation-guide-dialog', false)
            ->assertSee('Как подтвердить аккаунт')
            ->assertSee('Обязательны подтверждённый основной контакт и выбранная роль участия.')
            ->assertSee('data-account-confirmation-guide-close', false);

        $this->get(route('account.profile'))->assertOk()
            ->assertSee('href="'.route('account.confirmation').'"', false)
            ->assertDontSee('data-account-confirmation-guide-open', false)
            ->assertDontSee('data-account-confirmation-guide-dialog', false);
    }

    public function test_only_unconfirmed_account_pages_except_confirmation_show_direct_link_and_attention_dots(): void
    {
        $user = $this->account();

        foreach (['account', 'account.profile', 'account.roles', 'account.settings'] as $routeName) {
            $response = $this->actingAs($user)->get(route($routeName))->assertOk()
                ->assertSee('href="'.route('account.confirmation').'"', false)
                ->assertSee('app-context-actions__attention', false)
                ->assertSee('Подтвердить акк...')
                ->assertSee('aria-label="Подтвердить аккаунт"', false);

            $this->assertSame(2, substr_count($response->getContent(), 'class="app-context-actions__attention"'));
            if ($routeName !== 'account') {
                $response->assertDontSee('data-account-confirmation-guide-open', false)
                    ->assertDontSee('data-account-confirmation-guide-dialog', false);
            }
        }

        $overview = $this->get(route('account'))->assertOk()
            ->assertSee('app-account-overview__confirmation-badge', false)
            ->assertSee('Не подтверждён')
            ->assertSee('data-account-confirmation-guide-open', false)
            ->assertSee('data-account-confirmation-guide-dialog', false);
        $this->assertSame(1, substr_count($overview->getContent(), 'data-account-confirmation-guide-open'));

        $this->get(route('account.profile'))->assertOk()
            ->assertDontSee('app-account-overview__confirmation-badge', false)
            ->assertDontSee('app-profile__status', false);

        $this->get(route('account.confirmation'))->assertOk()
            ->assertDontSee('app-context-actions__attention', false)
            ->assertDontSee('data-account-confirmation-guide-open', false)
            ->assertDontSee('data-account-confirmation-guide-dialog', false);

        $this->get(route('faq.index'))->assertOk()
            ->assertDontSee('data-account-confirmation-guide-open', false)
            ->assertDontSee('data-account-confirmation-guide-dialog', false)
            ->assertDontSee('app-context-actions__attention', false);
    }

    public function test_confirmed_accounts_have_no_confirmation_attention_on_account_pages(): void
    {
        $user = $this->account(UserStatusEnum::CONFIRMED);

        foreach (['account', 'account.profile', 'account.roles'] as $routeName) {
            $this->actingAs($user)->get(route($routeName))->assertOk()
                ->assertDontSee('data-account-confirmation-guide-open', false)
                ->assertDontSee('data-account-confirmation-guide-dialog', false)
                ->assertDontSee('app-context-actions__attention', false)
                ->assertDontSee('app-account-overview__confirmation-badge', false);
        }
    }

    public function test_confirmation_badge_uses_published_faq_section_and_hides_other_sections(): void
    {
        $user = $this->account();

        ContentItem::query()->create([
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
            'type' => ContentTypeEnum::FAQ,
            'status' => ContentStatusEnum::PUBLISHED,
            'content_format' => ContentFormatEnum::SAFE_HTML,
            'title' => 'Первые шаги',
            'alias' => 'faq-welcome',
            'system_key' => 'faq.welcome',
            'short_description' => 'Первые шаги пользователя',
            'full_description' => '[anchor id="contact-confirmation"]<h2>Подтверждение контакта</h2><p>Не показывай этот раздел!</p>'
                .'[anchor id="account-confirmation"]<h2>3. Как подтвердить аккаунт</h2>'
                .'<p>Обновлённая редактором FAQ инструкция.</p><script>window.maliciousFaqScript = true</script><p><a href="/account/confirmation">Перейти к подтверждению</a></p>'
                .'[anchor id="creation-permissions"]<p>Не показывай следующий раздел!</p>',
        ]);

        $html = $this->actingAs($user)->get(route('account'))->assertOk()->getContent();
        $this->assertStringContainsString('Обновлённая редактором FAQ инструкция.', $html);
        $this->assertStringContainsString('Перейти к подтверждению', $html);
        $this->assertStringNotContainsString('window.maliciousFaqScript', $html);
        $this->assertStringNotContainsString('Не показывай этот раздел!', $html);
        $this->assertStringNotContainsString('Не показывай следующий раздел!', $html);
        $this->assertStringNotContainsString('Обязательны подтверждённый основной контакт', $html);
    }

    public function test_unpublished_welcome_faq_is_not_exposed_in_status_dialog(): void
    {
        $user = $this->account();

        ContentItem::query()->create([
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
            'type' => ContentTypeEnum::FAQ,
            'status' => ContentStatusEnum::DRAFT,
            'content_format' => ContentFormatEnum::SAFE_HTML,
            'title' => 'Первые шаги',
            'alias' => 'faq-welcome',
            'system_key' => 'faq.welcome',
            'short_description' => 'Черновик FAQ',
            'full_description' => '[anchor id="account-confirmation"]<h2>Черновик</h2><p>Секретный текст черновика</p>',
        ]);

        $this->actingAs($user)->get(route('account'))->assertOk()
            ->assertSee('Инструкция по подтверждению аккаунта сейчас недоступна.')
            ->assertDontSee('Секретный текст черновика')
            ->assertDontSee('Обязательны подтверждённый основной контакт');
    }

    public function test_unconfirmed_user_can_edit_name_patrimony_birthday_and_gender(): void
    {
        $user = $this->account();
        $this->actingAs($user)->patch(route('account.profile.update'), [
            'first_name' => '  Анна  ',
            'last_name' => 'Петрова',
            'middle_name' => '',
            'birth_date' => '1999-03-01',
            'gender' => 'male',
        ])->assertRedirect(route('account.profile'))
            ->assertSessionHas('profile_status', 'Данные профиля сохранены.');

        $profile = $user->profile->fresh();
        $this->assertSame('Анна', $profile->first_name);
        $this->assertSame('Петрова', $profile->last_name);
        $this->assertNull($profile->middle_name);
        $this->assertSame('1999-03-01', $profile->birth_date->toDateString());
        $this->assertSame(UserGenderEnum::MALE, $profile->gender);
    }

    public function test_confirmed_user_can_edit_patronymic_but_not_verified_attributes(): void
    {
        $user = $this->account(UserStatusEnum::CONFIRMED);

        $this->actingAs($user)->get(route('account.profile'))->assertOk()
            ->assertSee('Запросить изменение подтверждённых данных')
            ->assertSee('data-profile-change-request-dialog', false)
            ->assertDontSee('data-account-confirmation-guide-open', false)
            ->assertSee('name="middle_name"', false)
            ->assertDontSee('name="first_name"', false)
            ->assertDontSee('name="birth_date"', false)
            ->assertDontSee('name="gender"', false);

        $this->patch(route('account.profile.update'), [
            'middle_name' => 'Андреевна',
        ])->assertRedirect(route('account.profile'));
        $this->assertSame('Андреевна', $user->profile->fresh()->middle_name);

        foreach (['first_name', 'last_name', 'birth_date', 'gender'] as $attribute) {
            $this->patch(route('account.profile.update'), [
                $attribute => $attribute === 'gender' ? 'male' : 'test',
            ])->assertForbidden();
        }
        $profile = $user->profile->fresh();
        $this->assertSame('Мария', $profile->first_name);
        $this->assertSame('Иванова', $profile->last_name);
        $this->assertSame('2001-05-20', $profile->birth_date->toDateString());
        $this->assertSame(UserGenderEnum::FEMALE, $profile->gender);
    }

    public function test_validated_changes_do_not_allow_invalid_birth_date_gender_or_extra_fields(): void
    {
        $user = $this->account();
        $this->actingAs($user)->patch(route('account.profile.update'), [
            'first_name' => 'Изменённое',
            'birth_date' => now()->addDay()->format('Y-m-d'),
            'gender' => 'other',
        ])->assertSessionHasErrors(['birth_date', 'gender']);

        $this->assertSame('Мария', $user->profile->fresh()->first_name);
        $this->patch(route('account.profile.update'), [
            'system_role' => 'superadmin', 'nickname' => 'hacker',
        ])->assertRedirect(route('account.profile'));
        $this->assertNull($user->fresh()->nickname);
        $this->assertSame('Мария', $user->profile->fresh()->first_name);
    }

    public function test_existing_nickname_endpoint_keeps_public_url_and_prevents_collisions(): void
    {
        $user = $this->account(UserStatusEnum::CONFIRMED);
        $this->actingAs($user)->patchJson(route('account.nickname.update'), [
            'nickname' => 'MariQ',
        ])->assertOk()->assertJsonPath('nickname', 'mariq')
            ->assertJsonPath('public_url', route('users.show', ['user' => 'mariq']));
        $this->assertSame('mariq', $user->fresh()->nickname);
        $this->assertSame($user->username, $user->fresh()->username);

        $other = User::factory()->create(['username' => 'unique_other']);
        $this->patchJson(route('account.nickname.update'), ['nickname' => $other->username])
            ->assertUnprocessable()->assertJsonValidationErrors('nickname');
    }

    public function test_avatar_library_reuses_upload_activate_delete_routes(): void
    {
        Storage::fake('public');
        $user = $this->account();
        $this->actingAs($user)->post(route('account.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('face.png', 350, 350),
        ])->assertRedirect()->assertSessionHas('avatar_status');
        $avatar = $user->profile->avatars()->firstOrFail();
        $this->get(route('account.profile'))->assertOk()
            ->assertSee($avatar->publicUrl(), false)
            ->assertSee(route('account.avatar.destroy', $avatar->id), false)
            ->assertSee('Основной');

        $this->post(route('account.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('new.jpg', 400, 400),
        ])->assertRedirect();
        $this->get(route('account.profile'))->assertOk()
            ->assertSee(route('account.avatar.activate', $avatar->id), false)
            ->assertSee('Сделать основным');
        $this->patch(route('account.avatar.activate', $avatar->id))->assertRedirect();
        $this->assertTrue($avatar->fresh()->is_featured);
        $this->delete(route('account.avatar.destroy', $avatar->id))->assertRedirect();
        $this->assertSoftDeleted('media', ['id' => $avatar->id]);
    }

    public function test_no_profile_yet_renders_without_writing_on_get_and_can_create_on_patch(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('account.profile'))->assertOk()
            ->assertSee('Сначала сохрани личные данные');
        $this->assertNull($user->fresh()->profile);
        $this->patch(route('account.profile.update'), ['first_name' => 'Ирина'])
            ->assertRedirect(route('account.profile'));
        $this->assertSame('Ирина', $user->fresh()->profile->first_name);
    }

    public function test_public_link_falls_back_to_login_when_nickname_is_empty(): void
    {
        $user = User::factory()->create(['username' => 'legacy_login_26']);
        $this->actingAs($user)->get(route('account.profile'))->assertOk()
            ->assertSee(route('users.show', ['user' => 'legacy_login_26']), false)
            ->assertDontSee('id="app-profile-username"', false);
    }

    public function test_alias_identity_cannot_bypass_confirmed_personal_data_lock(): void
    {
        $canonical = $this->account(UserStatusEnum::CONFIRMED);
        $alias = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        $alias->forceFill(['canonical_user_id' => $canonical->id])->save();

        $this->actingAs($alias)->patch(route('account.profile.update'), [
            'first_name' => 'Обход',
        ])->assertForbidden();
        $this->assertSame('Мария', $canonical->profile->fresh()->first_name);
    }

    public function test_legacy_route_redirect_and_legacy_mutation_unavailable(): void
    {
        $this->switchTheme('mskba_dark');
        $this->actingAs($this->account())->get(route('account.profile'))
            ->assertRedirect(route('account'));
        $this->patch(route('account.profile.update'), ['middle_name' => 'Test'])->assertNotFound();
    }
}
