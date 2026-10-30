<?php

namespace Tests\Feature\Ai;

use App\Modules\Ai\Application\Queries\GetPlayerAvatarGenerationOffer;
use App\Modules\Finance\Application\UseCases\CreditWalletHandler;
use App\Modules\Finance\Application\UseCases\EnsureWalletHandler;
use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Finance\Domain\Models\WalletOperation;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Pricing\Application\Services\PricingPriceResolver;
use App\Modules\Rewards\Domain\Models\Reward;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class PlayerAvatarGenerationOfferTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTheme = (string) config('themes.active');
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    protected function tearDown(): void
    {
        config()->set('themes.active', $this->previousTheme);
        View::replaceNamespace('theme', resource_path('themes/'.$this->previousTheme.'/views'));
        app()->forgetInstance(ThemeResolver::class);
        parent::tearDown();
    }

    private function player(): User
    {
        $user = User::factory()->create(['system_role' => UserSystemRoleEnum::USER]);
        $user->profile()->create(['gender' => UserGenderEnum::MALE]);
        $user->participationRoles(false)->create([
            'role' => UserParticipationRoleEnum::PLAYER,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);

        return $user;
    }

    private function quote(User $user)
    {
        return $this->actingAs($user)->patchJson(route('account.player-profile.update'), [
            'mutation' => 'generation_quote',
        ]);
    }

    public function test_zero_balance_offer_is_read_only_and_generation_form_is_disabled(): void
    {
        $user = $this->player();

        $this->quote($user)->assertOk()->assertHeader('Cache-Control')
            ->assertJsonPath('price_minor', 10000)
            ->assertJsonPath('bonus_balance_minor', 0)
            ->assertJsonPath('real_balance_minor', 0)
            ->assertJsonPath('available_minor', 0)
            ->assertJsonPath('available_generations', 0)
            ->assertJsonPath('can_generate', false);

        $this->actingAs($user)->get(route('account.participation-role', ['role' => 'player']))
            ->assertOk()
            ->assertSee('Генерация аватара')
            ->assertSee('Недостаточно средств')
            ->assertSee('Как пополнить?')
            ->assertSee('data-player-generation-affordable="false"', false)
            ->assertSee('Оплата картой пока недоступна');

        $this->assertSame(0, WalletOperation::query()->count());
    }

    public function test_offer_counts_bonus_first_and_real_money_from_finance_in_kopecks(): void
    {
        $user = $this->player();
        $wallet = app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $user->id);
        $credit = app(CreditWalletHandler::class);
        $credit->handle(
            wallet: $wallet,
            balanceType: WalletBalanceTypeEnum::BONUS,
            amountMinor: 17500,
            operationType: WalletOperationTypeEnum::BONUS_GRANT,
            idempotencyKey: 'avatar-058-bonus-'.$user->id,
        );
        $credit->handle(
            wallet: $wallet,
            balanceType: WalletBalanceTypeEnum::REAL,
            amountMinor: 3000,
            operationType: WalletOperationTypeEnum::TOP_UP,
            idempotencyKey: 'avatar-058-real-'.$user->id,
        );

        $this->quote($user)->assertOk()
            ->assertJsonPath('price_minor', 10000)
            ->assertJsonPath('bonus_balance_minor', 17500)
            ->assertJsonPath('real_balance_minor', 3000)
            ->assertJsonPath('available_minor', 20500)
            ->assertJsonPath('available_generations', 2)
            ->assertJsonPath('can_generate', true);
        $this->actingAs($user)->get(route('account.participation-role', ['role' => 'player']))
            ->assertOk()
            ->assertSee('175,00')
            ->assertSee('205,00')
            ->assertSee('data-player-generation-fields', false)
            ->assertSee('data-player-generation-affordable="true"', false);
        $this->assertSame(2, WalletOperation::query()->count());
    }

    public function test_current_pricing_change_recalculates_quote_without_any_hard_coded_100_rubles(): void
    {
        $user = $this->player();
        $wallet = app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $user->id);
        app(CreditWalletHandler::class)->handle(
            wallet: $wallet,
            balanceType: WalletBalanceTypeEnum::BONUS,
            amountMinor: 25000,
            operationType: WalletOperationTypeEnum::BONUS_GRANT,
            idempotencyKey: 'avatar-058-new-price-'.$user->id,
        );
        $price = app(PricingPriceResolver::class)->resolve('avatar_generation');
        $this->assertNotNull($price);
        $price->update(['amount_minor' => 13000]);

        $this->quote($user)->assertOk()
            ->assertJsonPath('price_minor', 13000)
            ->assertJsonPath('available_generations', 1)
            ->assertJsonPath('can_generate', true);
    }

    public function test_inactive_pricing_never_falls_back_to_hard_coded_price_or_charges_wallet(): void
    {
        $user = $this->player();
        $price = app(PricingPriceResolver::class)->resolve('avatar_generation');
        $price->update(['is_active' => false]);

        $this->quote($user)->assertOk()
            ->assertJsonPath('price_minor', null)
            ->assertJsonPath('available_generations', null)
            ->assertJsonPath('can_generate', false);
        $this->assertSame(0, WalletOperation::query()->count());
    }

    public function test_bonus_offer_lists_only_enabled_catalog_rewards_without_granting_anything(): void
    {
        $user = $this->player();
        $offers = app(GetPlayerAvatarGenerationOffer::class)->handle($user);
        $listed = collect($offers['bonus_opportunities'])->pluck('code');
        $this->assertContains('venue_first_approval', $listed->all());
        $this->assertContains('referral_user_confirmed', $listed->all());

        Reward::query()->where('code', 'venue_first_approval')->update(['is_enabled' => false]);
        $after = app(GetPlayerAvatarGenerationOffer::class)->handle($user);
        $this->assertNotContains('venue_first_approval', collect($after['bonus_opportunities'])->pluck('code')->all());
        $this->assertSame(0, WalletOperation::query()->count());
    }

    public function test_help_payments_section_uses_the_same_enabled_reward_catalogue(): void
    {
        $this->getJson(route('faq.help-content', ['context' => 'payments']))
            ->assertOk()
            ->assertJsonPath('section', 'payments')
            ->assertJsonPath('breadcrumbs.1.label', 'Оплата и бонусы')
            ->assertJsonPath('bonus_opportunities.0.code', 'venue_first_approval');

        Reward::query()->where('code', 'venue_first_approval')->update(['is_enabled' => false]);
        $this->getJson(route('faq.help-content', ['context' => 'payments']))
            ->assertOk()
            ->assertJsonMissing(['code' => 'venue_first_approval']);
    }
}
