<?php

namespace App\Modules\Ai\Application\Queries;

use App\Modules\Finance\Application\Services\WalletOwnerResolver;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletTypeEnum;
use App\Modules\Finance\Domain\Models\Wallet;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Pricing\Application\Services\PricingPriceResolver;
use App\Modules\Rewards\Domain\Models\Reward;

final readonly class GetPlayerAvatarGenerationOffer
{
    private const SERVICE_CODE = 'avatar_generation';

    private const EARNING_MECHANISMS = [
        'venue_first_approval' => 'Подтверждённое добавление новой площадки',
        'referral_user_confirmed' => 'Приглашение друга с подтверждённым аккаунтом',
        'referral_second_level_user_confirmed' => 'Подтверждение приглашённого пользователя второго уровня',
    ];

    public function __construct(
        private PricingPriceResolver $prices,
        private WalletOwnerResolver $owners,
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

    /** @return list<array{code: string, title: string, amount_minor: int, description: string}> */
    public function bonusOpportunities(): array
    {
        $rewards = Reward::query()
            ->whereIn('code', array_keys(self::EARNING_MECHANISMS))
            ->where('is_enabled', true)
            ->whereHas('currentVersion')
            ->with('currentVersion')
            ->get()
            ->keyBy('code');

        $result = [];
        foreach (self::EARNING_MECHANISMS as $code => $title) {
            $version = $rewards->get($code)?->currentVersion;
            if ($version === null
                || $version->mechanism_code !== $code
                || $version->currency !== 'RUB'
                || (int) $version->amount_minor <= 0
                || ($version->valid_from !== null && $version->valid_from->isFuture())
                || ($version->valid_until !== null && $version->valid_until->isPast())) {
                continue;
            }
            $result[] = [
                'code' => $code,
                'title' => $title,
                'amount_minor' => (int) $version->amount_minor,
                'description' => (string) ($version->trigger_description ?: 'Бонус начисляется после выполнения и проверки условия.'),
            ];
        }

        return $result;
    }
}
