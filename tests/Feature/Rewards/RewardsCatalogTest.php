<?php

namespace Tests\Feature\Rewards;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Rewards\Application\Contracts\RewardMechanism;
use App\Modules\Rewards\Application\Data\RewardMechanismContext;
use App\Modules\Rewards\Application\Data\RewardMechanismDecision;
use App\Modules\Rewards\Domain\Models\Reward;
use App\Modules\Rewards\Domain\Models\RewardVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RewardsCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_catalog_contains_planned_rewards_but_keeps_them_disabled(): void
    {
        $referral = Reward::query()->where('code', 'referral_user_confirmed')->firstOrFail();
        $secondLevelReferral = Reward::query()->where('code', 'referral_second_level_user_confirmed')->firstOrFail();
        $venue = Reward::query()->where('code', 'venue_first_approval')->firstOrFail();

        $this->assertFalse($referral->is_enabled);
        $this->assertFalse($secondLevelReferral->is_enabled);
        $this->assertFalse($venue->is_enabled);
        $this->assertSame(30000, $referral->currentVersion()->firstOrFail()->amount_minor);
        $this->assertSame(10000, $secondLevelReferral->currentVersion()->firstOrFail()->amount_minor);
        $this->assertSame(10000, $venue->currentVersion()->firstOrFail()->amount_minor);
    }

    public function test_rewards_catalog_is_available_only_to_superadmin(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.rewards.index'))
            ->assertForbidden();

        $this->actingAs($this->superadmin())
            ->get(route('admin.rewards.index'))
            ->assertOk()
            ->assertSee('Вознаграждения')
            ->assertSee('Подтверждённый приглашённый пользователь')
            ->assertSee('Подтверждённый пользователь второго уровня')
            ->assertSee('Первая успешная модерация новой площадки');
    }

    public function test_superadmin_can_create_reward_and_standard_audit_is_written(): void
    {
        config()->set('audit.ignore_console', false);

        $this->actingAs($this->superadmin())
            ->post(route('admin.rewards.store'), [
                'code' => 'profile_completed',
                'name' => 'Заполненный профиль',
                'description' => 'Будущее вознаграждение.',
                'mechanism_code' => 'profile_completed',
                'is_enabled' => '0',
                'amount_rub' => '50.00',
                'recipient_description' => 'Пользователь, заполнивший профиль.',
                'trigger_description' => 'После достижения требуемой полноты профиля.',
                'conditions' => 'Конкретный критерий будет задан механизмом.',
            ])
            ->assertRedirect();

        $reward = Reward::query()->where('code', 'profile_completed')->firstOrFail();

        $this->assertFalse($reward->is_enabled);
        $this->assertSame(5000, $reward->currentVersion()->firstOrFail()->amount_minor);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Reward::class,
            'auditable_id' => $reward->id,
            'event' => 'created',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => RewardVersion::class,
            'auditable_id' => $reward->currentVersion()->firstOrFail()->id,
            'event' => 'created',
        ]);
    }

    public function test_changing_amount_or_conditions_creates_new_version_without_rewriting_history(): void
    {
        $reward = Reward::query()->where('code', 'referral_user_confirmed')->firstOrFail();
        $oldVersion = $reward->currentVersion()->firstOrFail();

        $this->actingAs($this->superadmin())
            ->put(route('admin.rewards.update', $reward), [
                'name' => $reward->name,
                'description' => $reward->description,
                'mechanism_code' => $reward->mechanism_code,
                'is_enabled' => '0',
                'amount_rub' => '350.00',
                'recipient_description' => $oldVersion->recipient_description,
                'trigger_description' => $oldVersion->trigger_description,
                'conditions' => 'Обновлённые условия будущего механизма.',
            ])
            ->assertRedirect();

        $oldVersion->refresh();
        $reward->refresh();

        $newVersion = $reward->currentVersion()->firstOrFail();

        $this->assertNotNull($oldVersion->valid_until);
        $this->assertSame(1, $oldVersion->version_number);
        $this->assertSame(30000, $oldVersion->amount_minor);
        $this->assertSame(2, $newVersion->version_number);
        $this->assertSame(35000, $newVersion->amount_minor);
        $this->assertSame('Обновлённые условия будущего механизма.', $newVersion->conditions);
        $this->assertSame(2, RewardVersion::query()->where('reward_id', $reward->id)->count());
    }

    public function test_unimplemented_mechanism_cannot_be_enabled(): void
    {
        $reward = Reward::query()->where('code', 'referral_user_confirmed')->firstOrFail();
        $version = $reward->currentVersion()->firstOrFail();

        $this->actingAs($this->superadmin())
            ->from(route('admin.rewards.index'))
            ->put(route('admin.rewards.update', $reward), [
                'name' => $reward->name,
                'description' => $reward->description,
                'mechanism_code' => 'not_implemented',
                'is_enabled' => '1',
                'amount_rub' => '300.00',
                'recipient_description' => $version->recipient_description,
                'trigger_description' => $version->trigger_description,
                'conditions' => $version->conditions,
            ])
            ->assertRedirect(route('admin.rewards.index'))
            ->assertSessionHasErrors('is_enabled');

        $this->assertFalse($reward->fresh()->is_enabled);
    }

    public function test_registered_mechanism_can_be_enabled_and_validates_parameters(): void
    {
        config()->set('rewards.mechanisms', [TestRewardMechanism::class]);

        $reward = Reward::query()->where('code', 'referral_user_confirmed')->firstOrFail();
        $version = $reward->currentVersion()->firstOrFail();

        $this->actingAs($this->superadmin())
            ->put(route('admin.rewards.update', $reward), [
                'name' => $reward->name,
                'description' => $reward->description,
                'mechanism_code' => 'test_reward',
                'is_enabled' => '1',
                'amount_rub' => '300.00',
                'recipient_description' => $version->recipient_description,
                'trigger_description' => $version->trigger_description,
                'conditions' => $version->conditions,
                'mechanism_parameters' => [
                    'threshold' => 2,
                ],
            ])
            ->assertRedirect();

        $reward->refresh();
        $current = $reward->currentVersion()->firstOrFail();

        $this->assertTrue($reward->is_enabled);
        $this->assertSame('test_reward', $reward->mechanism_code);
        $this->assertSame(['threshold' => 2], $current->mechanism_parameters);
    }

    public function test_delete_soft_deletes_reward_disables_it_and_closes_current_version(): void
    {
        config()->set('rewards.mechanisms', [TestRewardMechanism::class]);

        $reward = Reward::query()->where('code', 'referral_user_confirmed')->firstOrFail();
        $version = $reward->currentVersion()->firstOrFail();

        $this->actingAs($this->superadmin())
            ->put(route('admin.rewards.update', $reward), [
                'name' => $reward->name,
                'description' => $reward->description,
                'mechanism_code' => 'test_reward',
                'is_enabled' => '1',
                'amount_rub' => '300.00',
                'recipient_description' => $version->recipient_description,
                'trigger_description' => $version->trigger_description,
                'conditions' => $version->conditions,
                'mechanism_parameters' => ['threshold' => 1],
            ])
            ->assertRedirect();

        $currentVersionId = $reward->fresh()->currentVersion()->firstOrFail()->id;

        $this->actingAs($this->superadmin())
            ->delete(route('admin.rewards.destroy', $reward))
            ->assertRedirect();

        $deleted = Reward::withTrashed()->findOrFail($reward->id);

        $this->assertNotNull($deleted->deleted_at);
        $this->assertFalse($deleted->is_enabled);
        $this->assertNotNull(RewardVersion::query()->findOrFail($currentVersionId)->valid_until);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
    }

    private function superadmin(): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
    }
}

final class TestRewardMechanism implements RewardMechanism
{
    public function code(): string
    {
        return 'test_reward';
    }

    public function label(): string
    {
        return 'Тестовый механизм';
    }

    public function parameterRules(): array
    {
        return [
            'threshold' => ['required', 'integer', 'min:1'],
        ];
    }

    public function evaluate(
        RewardMechanismContext $context,
        RewardVersion $version,
    ): RewardMechanismDecision {
        return RewardMechanismDecision::eligible(1);
    }
}
