<?php

namespace App\Modules\Rewards\Application\Services;

use App\Modules\Rewards\Domain\Models\Reward;
use App\Modules\Rewards\Domain\Models\RewardVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RewardCatalogManager
{
    public function __construct(
        private readonly RewardMechanismRegistry $mechanisms,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Reward
    {
        $mechanismCode = $this->normalizeMechanismCode($attributes['mechanism_code'] ?? null);
        $isEnabled = (bool) ($attributes['is_enabled'] ?? false);
        $parameters = $this->mechanisms->validateParameters(
            $mechanismCode,
            (array) ($attributes['mechanism_parameters'] ?? []),
        );

        $this->assertCanEnable($mechanismCode, $isEnabled);

        return DB::transaction(function () use ($attributes, $mechanismCode, $isEnabled, $parameters): Reward {
            $reward = Reward::query()->create([
                'code' => (string) $attributes['code'],
                'name' => (string) $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'mechanism_code' => $mechanismCode,
                'is_enabled' => $isEnabled,
            ]);

            $reward->versions()->create($this->versionPayload(
                attributes: $attributes,
                mechanismCode: $mechanismCode,
                parameters: $parameters,
                versionNumber: 1,
                validFrom: now(),
            ));

            return $reward->fresh(['currentVersion', 'versions']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Reward $reward, array $attributes): Reward
    {
        $mechanismCode = $this->normalizeMechanismCode($attributes['mechanism_code'] ?? null);
        $isEnabled = (bool) ($attributes['is_enabled'] ?? false);
        $parameters = $this->mechanisms->validateParameters(
            $mechanismCode,
            (array) ($attributes['mechanism_parameters'] ?? []),
        );

        $this->assertCanEnable($mechanismCode, $isEnabled);

        return DB::transaction(function () use ($reward, $attributes, $mechanismCode, $isEnabled, $parameters): Reward {
            $lockedReward = Reward::query()->lockForUpdate()->findOrFail($reward->id);

            $lockedReward->update([
                'name' => (string) $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'mechanism_code' => $mechanismCode,
                'is_enabled' => $isEnabled,
            ]);

            $currentVersion = RewardVersion::query()
                ->where('reward_id', $lockedReward->id)
                ->whereNull('valid_until')
                ->lockForUpdate()
                ->orderByDesc('version_number')
                ->first();

            $nextVersionNumber = ($currentVersion?->version_number ?? 0) + 1;
            $nextPayload = $this->versionPayload(
                attributes: $attributes,
                mechanismCode: $mechanismCode,
                parameters: $parameters,
                versionNumber: $nextVersionNumber,
                validFrom: now(),
            );

            if ($currentVersion === null || $this->versionChanged($currentVersion, $nextPayload)) {
                $now = now();

                if ($currentVersion !== null) {
                    $currentVersion->update(['valid_until' => $now]);
                }

                $nextPayload['valid_from'] = $now;
                $lockedReward->versions()->create($nextPayload);
            }

            return $lockedReward->fresh(['currentVersion', 'versions']);
        });
    }

    public function delete(Reward $reward): void
    {
        DB::transaction(function () use ($reward): void {
            $lockedReward = Reward::query()->lockForUpdate()->findOrFail($reward->id);
            $now = now();

            if ($lockedReward->is_enabled) {
                $lockedReward->update(['is_enabled' => false]);
            }

            RewardVersion::query()
                ->where('reward_id', $lockedReward->id)
                ->whereNull('valid_until')
                ->lockForUpdate()
                ->get()
                ->each(fn (RewardVersion $version) => $version->update(['valid_until' => $now]));

            $lockedReward->delete();
        });
    }

    private function assertCanEnable(?string $mechanismCode, bool $isEnabled): void
    {
        if (! $isEnabled) {
            return;
        }

        if ($mechanismCode === null || ! $this->mechanisms->has($mechanismCode)) {
            throw ValidationException::withMessages([
                'is_enabled' => 'Начисление нельзя включить, пока механизм не реализован и не зарегистрирован.',
            ]);
        }
    }

    private function normalizeMechanismCode(mixed $value): ?string
    {
        $code = trim((string) ($value ?? ''));

        return $code === '' ? null : $code;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function versionPayload(
        array $attributes,
        ?string $mechanismCode,
        array $parameters,
        int $versionNumber,
        \DateTimeInterface $validFrom,
    ): array {
        return [
            'version_number' => $versionNumber,
            'amount_minor' => (int) $attributes['amount_minor'],
            'currency' => strtoupper((string) ($attributes['currency'] ?? 'RUB')),
            'mechanism_code' => $mechanismCode,
            'conditions' => $attributes['conditions'] ?? null,
            'recipient_description' => (string) $attributes['recipient_description'],
            'trigger_description' => (string) $attributes['trigger_description'],
            'mechanism_parameters' => $parameters === [] ? null : $parameters,
            'valid_from' => $validFrom,
            'valid_until' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $nextPayload
     */
    private function versionChanged(RewardVersion $current, array $nextPayload): bool
    {
        return $current->amount_minor !== $nextPayload['amount_minor']
            || $current->currency !== $nextPayload['currency']
            || $current->mechanism_code !== $nextPayload['mechanism_code']
            || (string) ($current->conditions ?? '') !== (string) ($nextPayload['conditions'] ?? '')
            || $current->recipient_description !== $nextPayload['recipient_description']
            || $current->trigger_description !== $nextPayload['trigger_description']
            || ($current->mechanism_parameters ?? []) !== ($nextPayload['mechanism_parameters'] ?? []);
    }
}
