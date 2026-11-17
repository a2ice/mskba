<?php

namespace App\Modules\Rewards\Application\Services;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Rewards\Domain\Models\Reward;
use App\Modules\Rewards\Domain\Models\VenueRewardOverride;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Validation\ValidationException;

/**
 * Venue-specific cashback controls. The global Reward remains the financial
 * source of truth; a venue may only inherit, disable or override its amount.
 */
final class VenueRentalCashbackSettings
{
    public function canManage(): bool
    {
        $actor = auth('web')->user()?->canonical();

        return $actor instanceof User
            && $actor->isConfirmed()
            && ! $actor->isBlocked()
            && $actor->system_role === UserSystemRoleEnum::SUPERADMIN;
    }

    public function formFields(Venue $venue): array
    {
        if (! $this->canManage()) {
            return [];
        }

        $reward = $this->rentalReward();
        $override = $reward === null ? null : VenueRewardOverride::query()
            ->where('venue_id', $venue->id)
            ->where('reward_id', $reward->id)
            ->first();

        return [
            'cashback_mode' => $override?->mode ?? 'inherit',
            'cashback_amount_rub' => $override?->mode === 'custom' && $override->amount_minor !== null
                ? number_format($override->amount_minor / 100, 2, '.', '')
                : null,
        ];
    }

    public function globalDescription(): string
    {
        $reward = $this->rentalReward();

        if ($reward === null) {
            return 'Глобальное вознаграждение за аренду не создано. Переопределение пока недоступно.';
        }

        if (! $reward->is_enabled || $reward->currentVersion === null) {
            return 'Глобальный кешбэк отключён. Локальные настройки не могут включить начисление.';
        }

        $kopecks = (int) $reward->currentVersion->amount_minor;
        $amount = number_format($kopecks / 100, $kopecks % 100 === 0 ? 0 : 2, ',', ' ');

        return "Глобальный кешбэк: {$amount} ₽ бонусами. По умолчанию действует для всех площадок. Отключение глобального вознаграждения имеет приоритет.";
    }

    /**
     * Called from the existing venue editor transaction only if the cashback
     * tab was submitted. Never rewrite overrides while editing other fields.
     *
     * @param array<string, mixed> $data
     */
    public function save(Venue $venue, array $data): void
    {
        if (! array_key_exists('cashback_mode', $data)) {
            return;
        }

        abort_unless($this->canManage(), 403);

        $reward = $this->rentalReward();
        if ($reward === null) {
            throw ValidationException::withMessages([
                'data.cashback_mode' => 'Глобальное вознаграждение за аренду не создано.',
            ]);
        }

        $mode = (string) $data['cashback_mode'];
        if (! in_array($mode, ['inherit', 'disabled', 'custom'], true)) {
            throw ValidationException::withMessages([
                'data.cashback_mode' => 'Выберите корректный режим кешбэка.',
            ]);
        }

        $override = VenueRewardOverride::query()->where('venue_id', $venue->id)
            ->where('reward_id', $reward->id)->first();

        // No row means inheritance, not a separate explicit global setting.
        if ($mode === 'inherit') {
            $override?->delete();

            return;
        }

        $amountMinor = null;
        if ($mode === 'custom') {
            $raw = str_replace(',', '.', trim((string) ($data['cashback_amount_rub'] ?? '')));
            if (! preg_match('/\A\d+(?:\.\d{1,2})?\z/D', $raw)) {
                throw ValidationException::withMessages([
                    'data.cashback_amount_rub' => 'Укажите сумму от 0 ₽, не более двух знаков после запятой.',
                ]);
            }
            [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
            if ((int) $whole > 10000000) {
                throw ValidationException::withMessages([
                    'data.cashback_amount_rub' => 'Сумма не может превышать 10 000 000 ₽.',
                ]);
            }
            $amountMinor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
            if ($amountMinor > 1000000000) {
                throw ValidationException::withMessages([
                    'data.cashback_amount_rub' => 'Сумма не может превышать 10 000 000 ₽.',
                ]);
            }
        }

        VenueRewardOverride::query()->updateOrCreate(
            ['venue_id' => $venue->id, 'reward_id' => $reward->id],
            ['mode' => $mode, 'amount_minor' => $amountMinor],
        );
    }

    private function rentalReward(): ?Reward
    {
        return Reward::query()->where('code', 'venue_rental_completed')
            ->with('currentVersion')->first();
    }
}
