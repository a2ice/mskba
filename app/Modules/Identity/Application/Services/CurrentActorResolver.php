<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\ActorTypeEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserFingerprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

final class CurrentActorResolver
{
    public function resolveForRequest(Request $request): ?Actor
    {
        $fingerprint = $request->attributes->get('browser_fingerprint');

        return $this->resolve(
            user: $request->user(),
            fingerprint: $fingerprint instanceof UserFingerprint ? $fingerprint : null,
        );
    }

    public function resolve(?User $user, ?UserFingerprint $fingerprint): ?Actor
    {
        if (! $this->actorsTableExists()) {
            return null;
        }

        if ($user !== null) {
            $user = $user->canonical();

            return $this->firstOrCreate(
                type: ActorTypeEnum::USER,
                user: $user,
                fingerprint: $fingerprint,
            );
        }

        if ($fingerprint !== null) {
            return $this->firstOrCreate(
                type: ActorTypeEnum::GUEST,
                user: null,
                fingerprint: $fingerprint,
            );
        }

        return null;
    }

    public function system(): Actor
    {
        return $this->firstOrCreate(
            type: ActorTypeEnum::SYSTEM,
            user: null,
            fingerprint: null,
        );
    }

    private function firstOrCreate(ActorTypeEnum $type, ?User $user, ?UserFingerprint $fingerprint): Actor
    {
        return Actor::query()->firstOrCreate(
            ['actor_key' => $this->actorKey($type, $user, $fingerprint)],
            [
                'type' => $type,
                'user_id' => $user?->id,
                'user_fingerprint_id' => $fingerprint?->id,
            ],
        );
    }

    private function actorKey(ActorTypeEnum $type, ?User $user, ?UserFingerprint $fingerprint): string
    {
        return implode(':', [
            $type->value,
            'user',
            $user?->id ?? 'none',
            'fingerprint',
            $fingerprint?->id ?? 'none',
        ]);
    }

    private function actorsTableExists(): bool
    {
        return Schema::hasTable('actors');
    }
}
