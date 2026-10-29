<?php

namespace Tests\Feature;

use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
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
            ->assertSee('app-confirmation__input-wrap select-control', false)
            ->assertSee('<use href="#chevron-down"/>', false)
            ->assertSee('name="birth_date"', false)
            ->assertSee('data-profile-personal-form', false)
            ->assertSee('data-inline-profile-save', false)
            ->assertSee('data-inline-profile-save-all', false)
            ->assertSee('data-inline-profile-loading', false)
            ->assertDontSee('->age', false)
            ->assertDontSee('Рассчитывается автоматически по дате рождения.')
            ->assertDontSee('Возраст:')
            ->assertDontSee('data-profile-change-request-open', false);
    }

    public function test_confirmation_uses_dynamic_contact_wizard_with_identity_providers(): void
    {
        $user = $this->account();
        $this->actingAs($user)->get(route('account.confirmation'))->assertOk()
            ->assertSee('data-contact-wizard', false)
            ->assertSee('data-wizard-step="method"', false)
            ->assertSee('data-wizard-step="email"', false)
            ->assertSee('data-wizard-step="code"', false)
            ->assertSee('data-telegram-start-url', false)
            ->assertSee('data-bot-link', false)
            ->assertSee('data-wizard-step="vk"', false)
            ->assertSee('SMS-подтверждение скоро появится')
            ->assertSee('data-wizard-next', false)
            ->assertDontSee('id="confirmation-contact-email"', false);
    }

    public function test_confirmation_has_single_profile_card_optional_patrimony_and_ajax_saves(): void
    {
        $user = $this->account();
        $response = $this->actingAs($user)->get(route('account.confirmation'))->assertOk()
            ->assertSee('id="confirmation-profile-title"', false)
            ->assertSee('name="middle_name"', false)
            ->assertSee('data-confirmation-save-field', false)
            ->assertSee('<use href="#save"/>', false)
            ->assertSee('data-confirmation-save-all', false)
            ->assertSee('Сохранить всё')
            ->assertSee('data-confirmation-requirement', false)
            ->assertSee('Управлять контактами')
            ->assertDontSee('Управлять контактами и выбрать основной')
            ->assertDontSee('confirmation-first-title');
        $html = $response->getContent();
        $this->assertLessThan(
            strpos($html, 'id="confirmation-main-contact"'),
            strpos($html, 'id="confirmation-profile-title"'),
        );
        $this->assertSame(5, substr_count($html, 'data-confirmation-save-field'));
        $this->assertStringNotContainsString('>Имя</h2>', $html);
    }

    public function test_confirmation_can_save_one_field_as_json_and_then_save_all(): void
    {
        $user = $this->account();
        $this->actingAs($user)->patchJson(route('account.profile.update'), [
            'first_name' => '  Наталья  ',
        ])->assertOk()->assertJsonPath('values.first_name', 'Наталья')
            ->assertJsonPath('message', 'Данные профиля сохранены.');
        $this->assertSame('Наталья', $user->profile->fresh()->first_name);
        $this->patchJson(route('account.profile.update'), [
            'last_name' => 'Смирнова',
            'middle_name' => 'Игоревна',
            'birth_date' => '1999-01-12',
            'gender' => 'female',
        ])->assertOk()->assertJsonPath('values.middle_name', 'Игоревна')
            ->assertJsonPath('values.birth_date', '1999-01-12');
        $profile = $user->profile->fresh();
        $this->assertSame('Смирнова', $profile->last_name);
        $this->assertSame('Игоревна', $profile->middle_name);
        $this->assertSame('1999-01-12', $profile->birth_date->toDateString());
    }

    public function test_confirmation_ajax_validation_failure_does_not_persist_any_fields(): void
    {
        $user = $this->account();
        $this->actingAs($user)->patchJson(route('account.profile.update'), [
            'first_name' => 'Изменено',
            'birth_date' => now()->addDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('birth_date');
        $this->assertSame('Мария', $user->profile->fresh()->first_name);
    }

    public function test_confirmed_profile_cannot_be_changed_by_confirmation_ajax(): void
    {
        $user = $this->account(UserStatusEnum::CONFIRMED);
        $this->actingAs($user)->patchJson(route('account.profile.update'), [
            'first_name' => 'Попытка',
        ])->assertForbidden();
        $this->assertSame('Мария', $user->profile->fresh()->first_name);
    }

    public function test_confirmed_superadmin_can_edit_own_personal_fields_immediately_and_audit(): void
    {
        $super = $this->account(UserStatusEnum::CONFIRMED);
        $super->forceFill(['system_role' => UserSystemRoleEnum::SUPERADMIN])->save();

        $this->actingAs($super)->get(route('account.profile'))->assertOk()
            ->assertSee('name="first_name"', false)
            ->assertSee('name="last_name"', false)
            ->assertSee('name="birth_date"', false)
            ->assertSee('name="gender"', false)
            ->assertSee('data-inline-profile-save-all', false)
            ->assertDontSee('data-profile-change-request-open', false)
            ->assertDontSee('data-profile-change-request-dialog', false);

        $this->patchJson(route('account.profile.update'), [
            'first_name' => '  Анна  ',
            'last_name' => 'Петрова',
            'birth_date' => '1994-04-04',
            'gender' => 'male',
        ])->assertOk()->assertJsonPath('values.first_name', 'Анна')
            ->assertJsonPath('values.gender', 'male');

        $saved = $super->profile->fresh();
        $this->assertSame('Анна', $saved->first_name);
        $this->assertSame('Петрова', $saved->last_name);
        $this->assertSame('1994-04-04', $saved->birth_date->toDateString());
        $this->assertSame(UserGenderEnum::MALE, $saved->gender);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => User::class,
            'auditable_id' => $super->id,
            'event' => 'self_confirmed_profile_updated',
        ]);
        $this->assertDatabaseCount('profile_change_requests', 0);
    }

    public function test_confirmed_admin_permission_can_be_revoked_and_restored_for_public_self_edit(): void
    {
        $admin = $this->account(UserStatusEnum::CONFIRMED);
        $admin->forceFill(['system_role' => UserSystemRoleEnum::ADMIN])->save();
        $this->actingAs($admin)->patchJson(route('account.profile.update'), [
            'middle_name' => 'Владимировна',
        ])->assertOk();

        UserOperationalPermission::query()->updateOrCreate([
            'user_id' => $admin->id,
            'permission' => Permission::EDIT_OWN_PROFILE->value,
        ], ['is_allowed' => false]);
        $this->get(route('account.profile'))->assertOk()
            ->assertSee('data-profile-change-request-open', false)
            ->assertDontSee('data-inline-profile-save-all', false);
        $this->patchJson(route('account.profile.update'), [
            'first_name' => 'Запрещено',
        ])->assertForbidden();
        $this->assertSame('Мария', $admin->profile->fresh()->first_name);

        UserOperationalPermission::query()->where('user_id', $admin->id)
            ->where('permission', Permission::EDIT_OWN_PROFILE->value)->update(['is_allowed' => true]);
        $this->patchJson(route('account.profile.update'), [
            'first_name' => 'Елена',
        ])->assertOk();
        $this->assertSame('Елена', $admin->profile->fresh()->first_name);
    }

    public function test_delegated_regular_user_can_edit_confirmed_data_but_other_fields_stay_protected(): void
    {
        $user = $this->account(UserStatusEnum::CONFIRMED);
        UserOperationalPermission::query()->create([
            'user_id' => $user->id,
            'permission' => Permission::EDIT_OWN_PROFILE->value,
            'is_allowed' => true,
        ]);
        $this->actingAs($user)->patchJson(route('account.profile.update'), [
            'first_name' => '',
            'system_role' => 'superadmin',
        ])->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->assertSame('Мария', $user->profile->fresh()->first_name);
        $this->patchJson(route('account.profile.update'), [
            'first_name' => 'Делегировано',
            'system_role' => 'superadmin',
        ])->assertOk();
        $this->assertSame('Делегировано', $user->profile->fresh()->first_name);
        $this->assertSame(UserSystemRoleEnum::USER, $user->fresh()->system_role);
    }

    public function test_confirmed_superadmin_player_dialog_has_editable_personal_fields(): void
    {
        $super = $this->account(UserStatusEnum::CONFIRMED);
        $super->forceFill(['system_role' => UserSystemRoleEnum::SUPERADMIN])->save();
        $super->participationRoles(false)->create([
            'role' => UserParticipationRoleEnum::PLAYER,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $super->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
        $this->actingAs($super)
            ->get(route('account.participation-role', ['role' => 'player']))
            ->assertOk()->assertSee('data-player-personal-dialog', false)
            ->assertSee('id="app-player-personal-form"', false)
            ->assertSee('name="birth_date"', false)
            ->assertSee('name="gender"', false)
            ->assertDontSee('data-profile-change-request-open', false);
    }

    public function test_contact_management_screen_lists_contacts_and_allows_switching_primary(): void
    {
        $user = $this->account();
        $first = $user->contacts()->create([
            'type' => \App\Modules\Contact\Domain\Enums\ContactTypeEnum::EMAIL,
            'value' => 'first@example.test', 'is_primary' => true, 'verified_at' => now(),
        ]);
        $second = $user->contacts()->create([
            'type' => \App\Modules\Contact\Domain\Enums\ContactTypeEnum::EMAIL,
            'value' => 'second@example.test', 'is_primary' => false, 'verified_at' => now(),
        ]);

        $this->actingAs($user)->get(route('account.contacts'))->assertOk()
            ->assertSee('Мои контакты')
            ->assertSee('first@example.test')
            ->assertSee('second@example.test')
            ->assertSee(route('account.contacts.primary', $second), false);

        $this->patch(route('account.contacts.primary', $second))->assertRedirect(route('account.contacts'));
        $this->assertFalse((bool) $first->fresh()->is_primary);
        $this->assertTrue((bool) $second->fresh()->is_primary);
    }

    public function test_overview_has_status_card_linking_to_confirmation_page(): void
    {
        $user = $this->account();

        $this->actingAs($user)->get(route('account'))->assertOk()
            ->assertSee('Статус пользователя')
            ->assertSee('app-account-overview__confirmation-action', false)
            ->assertSee('href="'.route('account.confirmation').'"', false)
            ->assertDontSee('data-account-confirmation-guide-open', false)
            ->assertDontSee('app-account-overview__confirmation-badge', false);

        $this->get(route('account.profile'))->assertOk()
            ->assertSee('href="'.route('account.confirmation').'"', false)
            ->assertDontSee('data-account-confirmation-guide-open', false);
    }

    public function test_confirmed_overview_keeps_status_card_without_confirmation_action(): void
    {
        $user = $this->account(UserStatusEnum::CONFIRMED);

        $this->actingAs($user)->get(route('account'))->assertOk()
            ->assertSee('Статус пользователя')
            ->assertSee('Подтверждён')
            ->assertSee('app-account-overview__status-label--confirmed', false)
            ->assertDontSee('Не подтверждён')
            ->assertDontSee('app-account-overview__confirmation-action', false)
            ->assertDontSee('app-account-overview__confirmation-dot', false)
            ->assertDontSee('Подтвердить аккаунт');
    }

    public function test_unconfirmed_user_sees_confirmation_action_portal_wide_except_confirmation_page(): void
    {
        $user = $this->account();

        foreach (['account', 'account.profile', 'account.roles', 'account.settings'] as $routeName) {
            $response = $this->actingAs($user)->get(route($routeName))->assertOk()
                ->assertSee('href="'.route('account.confirmation').'"', false)
                ->assertSee('app-context-actions__attention', false)
                ->assertSee('Подтвердить акк...')
                ->assertSee('aria-label="Подтвердить аккаунт"', false);

            $this->assertSame(2, substr_count($response->getContent(), 'class="app-context-actions__attention"'));
            $html = $response->getContent();
            $document = new \DOMDocument;
            @$document->loadHTML($html);
            $xpath = new \DOMXPath($document);
            $this->assertSame(1, $xpath->query(
                '//details[@data-app-context-actions]/summary/span[contains(@class,"app-context-quick__label") and normalize-space(.)="Действия"]/following-sibling::span[contains(@class,"app-context-actions__attention")]',
            )->length);

            if ($routeName !== 'account') {
                $response->assertDontSee('data-account-confirmation-guide-open', false)
                    ->assertDontSee('data-account-confirmation-guide-dialog', false);
            }
        }

        $overview = $this->get(route('account'))->assertOk()
            ->assertSee('app-account-overview__confirmation-action', false)
            ->assertSee('app-account-overview__confirmation-dot', false)
            ->assertSee('Статус пользователя')
            ->assertSee('Не подтверждён');
        $this->assertSame(0, substr_count($overview->getContent(), 'data-account-confirmation-guide-open'));

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
            ->assertSee('app-context-actions__attention', false)
            ->assertSee('Подтвердить акк...');
    }

    public function test_attention_dot_remains_visible_but_stops_pulsing_when_actions_are_open(): void
    {
        $css = file_get_contents(resource_path('themes/mskba_app/css/context-bar.css'));
        $this->assertIsString($css);
        $this->assertSame(1, preg_match(
            '/\.app-context-actions\[open\]\s*>\s*summary\s+\.app-context-actions__attention\s*\{([^}]*)\}/',
            $css,
            $matches,
        ));
        $this->assertStringContainsString('animation:none', $matches[1]);
        $this->assertStringContainsString('box-shadow:none', $matches[1]);
        $this->assertStringNotContainsString('opacity:', $matches[1]);
        $this->assertStringNotContainsString('display:none', $matches[1]);
        // Dot stays in flex flow after the clipped label. No precomputed
        // position at the right edge of the entire 126px button.
        $this->assertMatchesRegularExpression(
            '/summary \.app-context-actions__attention\s*\{[^}]*position: relative;[^}]*top: -7\.5px;[^}]*left: auto;/s',
            $css,
        );
        $this->assertStringNotContainsString('left:calc(100% - 20px);', $css);
        $this->assertStringContainsString('max-width: 0;', $css);
        $this->assertStringContainsString('max-width: 80px;', $css);
        $this->assertStringContainsString('.app-context-actions[open] > .app-context-quick', $css);
        $this->assertStringContainsString('.app-context-actions[open] > summary .app-context-quick__label', $css);
        $this->assertStringContainsString('.app-context-actions[open] > summary .app-context-actions__attention', $css);
        $this->assertStringNotContainsString('top: auto', $css);
        $this->assertStringContainsString('@media(prefers-reduced-motion:reduce)', $css);
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

    public function test_confirmed_user_without_self_edit_permission_uses_change_request_instead(): void
    {
        $user = $this->account(UserStatusEnum::CONFIRMED);

        $this->actingAs($user)->get(route('account.profile'))->assertOk()
            ->assertSee('Запросить изменение подтверждённых данных')
            ->assertSee('data-profile-change-request-dialog', false)
            ->assertDontSee('data-account-confirmation-guide-open', false)
            ->assertDontSee('name="middle_name"', false)
            ->assertSee('action="'.route('account.profile-change-requests.store').'"', false)
            ->assertSee('name="first_name"', false)
            ->assertSee('name="birth_date"', false)
            ->assertSee('name="gender"', false);

        $this->patch(route('account.profile.update'), [
            'middle_name' => 'Андреевна',
        ])->assertForbidden();
        $this->assertSame('Сергеевна', $user->profile->fresh()->middle_name);

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
