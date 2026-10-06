<?php

namespace App\Modules\Rewards\Application\Services;

use App\Modules\Rewards\Application\Contracts\RewardMechanism;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

final class RewardMechanismRegistry
{
    /**
     * @return array<string, RewardMechanism>
     */
    public function all(): array
    {
        $mechanisms = [];

        foreach ((array) config('rewards.mechanisms', []) as $mechanismClass) {
            if (! is_string($mechanismClass) || ! class_exists($mechanismClass)) {
                continue;
            }

            $mechanism = app($mechanismClass);

            if (! $mechanism instanceof RewardMechanism) {
                continue;
            }

            $code = $mechanism->code();

            if (isset($mechanisms[$code])) {
                throw new LogicException("Reward mechanism code [{$code}] is registered more than once.");
            }

            $mechanisms[$code] = $mechanism;
        }

        return $mechanisms;
    }

    public function find(?string $code): ?RewardMechanism
    {
        if ($code === null || $code === '') {
            return null;
        }

        return $this->all()[$code] ?? null;
    }

    public function has(?string $code): bool
    {
        return $this->find($code) !== null;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function validateParameters(?string $code, array $parameters): array
    {
        if ($parameters === []) {
            return [];
        }

        $mechanism = $this->find($code);

        if ($mechanism === null) {
            throw ValidationException::withMessages([
                'mechanism_parameters' => 'Параметры можно задавать только для реализованного механизма.',
            ]);
        }

        return Validator::make($parameters, $mechanism->parameterRules())->validate();
    }
}
