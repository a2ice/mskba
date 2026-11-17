<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Events\UserAccountConfirmed;
use App\Modules\Identity\Domain\Exceptions\UserCannotBeChangedException;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;

final class CompleteAccountConfirmationWizardHandler
{
    public function handle(User $user, string $firstName, string $lastName, string $birthDate, UserGenderEnum $gender): User
    {
        $confirmedUserId = null;
        $canonical = $user->canonical();

        $confirmed = DB::transaction(function () use ($canonical, $firstName, $lastName, $birthDate, $gender, &$confirmedUserId): User {
            $locked = User::query()->whereKey($canonical->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== UserStatusEnum::UNCONFIRMED) {
                throw new UserCannotBeChangedException('Подтвердить можно только неподтверждённый аккаунт.');
            }

            $locked->profile()->updateOrCreate(['user_id' => $locked->id], [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'birth_date' => $birthDate,
                'gender' => $gender,
            ]);

            // Domain validation runs again inside the transaction; neither role nor
            // client-side state can bypass identity requirements.
            $locked->confirmAccount();
            $confirmedUserId = (int) $locked->id;

            return $locked->refresh();
        });

        if ($confirmedUserId !== null) {
            event(new UserAccountConfirmed($confirmedUserId));
        }

        return $confirmed;
    }
}
