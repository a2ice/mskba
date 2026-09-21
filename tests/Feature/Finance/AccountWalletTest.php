<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\UseCases\CreditWalletHandler;
use App\Modules\Finance\Application\UseCases\EnsureWalletHandler;
use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccountWalletTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_page_shows_zero_balance_without_creating_wallet_on_read(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.wallet'))
            ->assertOk()
            ->assertSee('Кошелёк')
            ->assertSee('0,00 ₽')
            ->assertSee('Операций пока нет.');

        $this->assertDatabaseCount('wallets', 0);
    }

    public function test_wallet_page_shows_balance_breakdown_and_operation_history(): void
    {
        $user = User::factory()->create();
        $wallet = app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $user->id);
        $credits = app(CreditWalletHandler::class);

        $credits->handle(
            $wallet,
            WalletBalanceTypeEnum::REAL,
            100_000,
            WalletOperationTypeEnum::TOP_UP,
            'wallet-ui-topup',
            $user->id,
        );
        $credits->handle(
            $wallet,
            WalletBalanceTypeEnum::BONUS,
            25_000,
            WalletOperationTypeEnum::REFERRAL_REWARD,
            'wallet-ui-referral',
            null,
            'referral',
            'wallet-ui-invite',
        );

        $this->actingAs($user)
            ->get(route('account.wallet'))
            ->assertOk()
            ->assertSee('1 250,00 ₽')
            ->assertSee('1 000,00 ₽')
            ->assertSee('250,00 ₽')
            ->assertSee('Пополнение')
            ->assertSee('Реферальное вознаграждение')
            ->assertSee('+1 000,00 ₽')
            ->assertSee('+250,00 ₽');
    }

    public function test_wallet_page_uses_canonical_wallet_for_user_alias(): void
    {
        $canonical = User::factory()->create();
        $alias = User::factory()->create(['canonical_user_id' => $canonical->id]);
        $wallet = app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $canonical->id);

        app(CreditWalletHandler::class)->handle(
            $wallet,
            WalletBalanceTypeEnum::BONUS,
            5_000,
            WalletOperationTypeEnum::REFERRAL_REWARD,
            'wallet-ui-canonical',
        );

        $this->actingAs($alias)
            ->get(route('account.wallet'))
            ->assertOk()
            ->assertSee('50,00 ₽');

        $this->assertDatabaseCount('wallets', 1);
    }

    public function test_account_menu_contains_wallet_link(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account'))
            ->assertOk()
            ->assertSee('Кошелёк')
            ->assertSee(route('account.wallet'), false);
    }
}
