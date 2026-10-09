<?php

namespace App\Presentation\Identity;

use App\Modules\Identity\Domain\Models\User;

/**
 * UI salutations use a first name when available, otherwise the account
 * login. This must never be used as the public identity display name.
 */
final class UserAddressing
{
    public function greetingName(?User $user): string
    {
        if ($user === null) {
            return 'участник';
        }

        $givenName = trim((string) $user->profile?->first_name);

        if ($givenName !== '') {
            return mb_strtoupper(mb_substr($givenName, 0, 1)).mb_substr($givenName, 1);
        }

        $username = trim((string) $user->username);

        return $username !== '' ? $username : 'участник';
    }
}
