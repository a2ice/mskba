<?php

namespace App\Modules\Ai\Application\Queries;

use App\Modules\Finance\Application\Services\WalletOwnerResolver;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletTypeEnum;
use App\Modules\Finance\Domain\Models\Wallet;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Pricing\Application\Services\PricingPriceResolver;
use App\Modules\Promotions\Application\Queries\ResolveActivePromotions;

final readonly class GetPlayerAvatarGenerationOffer
{
    private const SERVICE_CODE = 'avatar_generation';

    public function __construct(
        private PricingPriceResolver $prices,
        private WalletOwnerResolver $owners,
        private ResolveActivePromotions $promotions,
    ) {}

    /**
     * Read-only offer. Amounts are integer RUB kopecks; no wallet/bonus is
     * created and no charge/payment is attempted. Billing remains authoritative.
     *
     * @return array<string, mixed>
     */
    public function handle(User $user): array
    {
        $price = $this->prices->resolve(self::SERVICE_CODE);
        $priceMinor = $price === null ? null : (int) $price->amount_minor;
        $ownerId = $this->owners->canonicalOwnerId(WalletOwnerTypeEnum::USER, (int) $user->id);
        $wallet = Wallet::query()
            ->where('owner_type', WalletOwnerTypeEnum::USER->value)
            ->where('owner_id', $ownerId)
            ->where('type', WalletTypeEnum::MAIN->value)
            ->where('currency', 'RUB')
            ->first();
        $bonus = max(0, (int) ($wallet?->bonus_balance_minor ?? 0));
        $real = max(0, (int) ($wallet?->real_balance_minor ?? 0));
        $total = $bonus + $real;

        return [
            'price_minor' => $priceMinor,
            'currency' => 'RUB',
            'bonus_balance_minor' => $bonus,
            'real_balance_minor' => $real,
            'available_minor' => $total,
            'available_generations' => $priceMinor === null
                ? null
                : ($priceMinor === 0 ? null : intdiv($total, $priceMinor)),
            'can_generate' => $priceMinor !== null && ($priceMinor === 0 || $total >= $priceMinor),
            'bonus_opportunities' => $this->bonusOpportunities(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function bonusOpportunities(): array
    {
        return $this->promotions->forPlacement('avatar_topup');
    }
}
