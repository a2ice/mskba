<?php

namespace App\Modules\Vk\Application\UseCases;

use App\Modules\Identity\Application\Services\CanonicalUserResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Events\UserFirstLogin;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

final class CompleteVkAuthenticationHandler
{
    public function __construct(private readonly CanonicalUserResolver $canonicalUserResolver) {}

    public function handle(User $user): User
    {
        $canonical = $this->canonicalUserResolver->resolve($user);

        if ($user->status === UserStatusEnum::BLOCKED || $canonical->status === UserStatusEnum::BLOCKED) {
            throw new InvalidArgumentException('Аккаунт заблокирован. Обратитесь в поддержку.');
        }

        Auth::login($canonical, true);
        request()->session()->regenerate();

        $firstLoginMarked = User::query()->whereKey($canonical->id)->whereNull('first_logged_in_at')
            ->update(['first_logged_in_at' => now()]);

        if ($firstLoginMarked === 1) {
            event(new UserFirstLogin((int) $canonical->id));
            $canonical->forceFill(['first_logged_in_at' => now()]);
        }

        return $canonical;
    }
}
