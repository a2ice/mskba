<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\UseCases\CreditWalletHandler;
use App\Modules\Finance\Application\UseCases\DebitWalletHandler;
use App\Modules\Finance\Application\UseCases\EnsureWalletHandler;
use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletSpendingPolicyEnum;
use App\Modules\Finance\Domain\Exceptions\InsufficientWalletBalanceException;
use App\Modules\Finance\Domain\Exceptions\WalletIdempotencyException;
use App\Modules\Finance\Domain\Exceptions\WalletOperationsDisabledException;
use App\Modules\Finance\Domain\Models\Wallet;
use App\Modules\Finance\Domain\Models\WalletLedgerEntry;
use App\Modules\Finance\Domain\Models\WalletOperation;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class WalletLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_payment_spends_bonus_before_real_and_keeps_materialized_balance_in_sync(): void
    {
        $user = User::factory()->create();
        $wallet = app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $user->id);
        $credits = app(CreditWalletHandler::class);
        $debits = app(DebitWalletHandler::class);

        $credits->handle(
            $wallet,
            WalletBalanceTypeEnum::REAL,
            100_000,
            WalletOperationTypeEnum::TOP_UP,
            'topup-1',
            $user->id,
        );
        $credits->handle(
            $wallet,
            WalletBalanceTypeEnum::BONUS,
            50_000,
            WalletOperationTypeEnum::REFERRAL_REWARD,
            'referral-1',
            null,
            'referral',
            'invite-42',
        );

        $payment = $debits->handle(
            $wallet,
            120_000,
            WalletOperationTypeEnum::INTERNAL_SERVICE_PAYMENT,
            'service-payment-1',
            WalletSpendingPolicyEnum::BONUS_THEN_REAL,
            $user->id,
            'service',
            'avatar-generation',
        );

        $wallet->refresh();

        $this->assertSame(30_000, $wallet->real_balance_minor);
        $this->assertSame(0, $wallet->bonus_balance_minor);
        $this->assertSame(30_000, $wallet->totalBalanceMinor());
        $this->assertCount(2, $payment->entries);
        $this->assertSame(
            [-50_000, -70_000],
            $payment->entries->pluck('amount_minor')->all(),
        );
        $this->assertDatabaseCount('wallet_ledger_entries', 4);
    }

    public function test_same_idempotency_key_is_applied_once_and_cannot_be_reused_with_different_payload(): void
    {
        $user = User::factory()->create();
        $wallet = app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $user->id);
        $credits = app(CreditWalletHandler::class);

        $first = $credits->handle(
            $wallet,
            WalletBalanceTypeEnum::REAL,
            10_000,
            WalletOperationTypeEnum::TOP_UP,
            'same-key',
        );
        $repeat = $credits->handle(
            $wallet,
            WalletBalanceTypeEnum::REAL,
            10_000,
            WalletOperationTypeEnum::TOP_UP,
            'same-key',
        );

        $this->assertSame($first->id, $repeat->id);
        $this->assertSame(10_000, $wallet->refresh()->real_balance_minor);
        $this->assertDatabaseCount('wallet_operations', 1);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);

        try {
            $credits->handle(
                $wallet,
                WalletBalanceTypeEnum::REAL,
                20_000,
                WalletOperationTypeEnum::TOP_UP,
                'same-key',
            );
            $this->fail('Reusing an idempotency key with another payload must fail.');
        } catch (WalletIdempotencyException) {
            $this->assertSame(10_000, $wallet->refresh()->real_balance_minor);
            $this->assertDatabaseCount('wallet_ledger_entries', 1);
        }
    }

    public function test_failed_debit_is_atomic(): void
    {
        $user = User::factory()->create();
        $wallet = app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $user->id);
        app(CreditWalletHandler::class)->handle(
            $wallet,
            WalletBalanceTypeEnum::BONUS,
            5_000,
            WalletOperationTypeEnum::REFERRAL_REWARD,
            'small-bonus',
        );

        try {
            app(DebitWalletHandler::class)->handle(
                $wallet,
                10_000,
                WalletOperationTypeEnum::INTERNAL_SERVICE_PAYMENT,
                'too-large-payment',
            );
            $this->fail('Insufficient balance must reject the whole operation.');
        } catch (InsufficientWalletBalanceException) {
            $wallet->refresh();
            $this->assertSame(5_000, $wallet->bonus_balance_minor);
            $this->assertSame(0, $wallet->real_balance_minor);
            $this->assertDatabaseMissing('wallet_operations', ['idempotency_key' => 'too-large-payment']);
        }
    }

    public function test_non_user_owner_operations_are_disabled_by_default(): void
    {
        $wallet = Wallet::query()->create([
            'owner_type' => WalletOwnerTypeEnum::TEAM,
            'owner_id' => 999,
            'type' => 'main',
            'currency' => 'RUB',
            'real_balance_minor' => 0,
            'bonus_balance_minor' => 0,
        ]);

        $this->expectException(WalletOperationsDisabledException::class);

        app(CreditWalletHandler::class)->handle(
            $wallet,
            WalletBalanceTypeEnum::BONUS,
            1_000,
            WalletOperationTypeEnum::REFERRAL_REWARD,
            'disabled-team-credit',
        );
    }

    public function test_user_alias_resolves_to_the_canonical_wallet(): void
    {
        $canonical = User::factory()->create();
        $alias = User::factory()->create(['canonical_user_id' => $canonical->id]);
        $wallets = app(EnsureWalletHandler::class);

        $first = $wallets->handle(WalletOwnerTypeEnum::USER, $canonical->id);
        $second = $wallets->handle(WalletOwnerTypeEnum::USER, $alias->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($canonical->id, $second->owner_id);
        $this->assertDatabaseCount('wallets', 1);
    }

    public function test_ledger_and_completed_operation_are_immutable(): void
    {
        $user = User::factory()->create();
        $wallet = app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $user->id);
        $operation = app(CreditWalletHandler::class)->handle(
            $wallet,
            WalletBalanceTypeEnum::REAL,
            1_000,
            WalletOperationTypeEnum::TOP_UP,
            'immutable-operation',
        );

        $entry = WalletLedgerEntry::query()->firstOrFail();
        try {
            $entry->update(['amount_minor' => 2_000]);
            $this->fail('Ledger entries must be immutable.');
        } catch (LogicException) {
            $this->assertSame(1_000, $entry->refresh()->amount_minor);
        }

        $this->expectException(LogicException::class);
        WalletOperation::query()->findOrFail($operation->id)->update(['reference_key' => 'changed']);
    }
}
