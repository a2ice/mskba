<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Domain\ValueObjects\ContactValue;
use App\Modules\Identity\Domain\Models\User;
use InvalidArgumentException;

final class UserLoginResolver
{
    public function resolve(string $login): ?User
    {
        $login = trim($login);
        $userIds = [];

        $user = User::query()->where('username', $login)->first();

        if ($user !== null) {
            $userIds[] = (int) $user->getKey();
        }

        foreach ($this->contactCandidates($login) as [$type, $value]) {
            array_push($userIds, ...$this->contactUserIds($type, $value));
        }

        $userIds = array_values(array_unique($userIds));

        return count($userIds) === 1
            ? User::query()->find($userIds[0])
            : null;
    }

    /**
     * @return list<array{ContactTypeEnum, string}>
     */
    private function contactCandidates(string $login): array
    {
        $candidates = [];

        foreach ([ContactTypeEnum::EMAIL, ContactTypeEnum::PHONE, ContactTypeEnum::TELEGRAM] as $type) {
            try {
                $candidates[] = [$type, (new ContactValue($type, $login))->value()];
            } catch (InvalidArgumentException) {
                // Значение может соответствовать другому типу контакта.
            }
        }

        if (ctype_digit($login)) {
            $candidates[] = [ContactTypeEnum::TELEGRAM, $login];
        }

        return $candidates;
    }

    /**
     * @return list<int>
     */
    private function contactUserIds(ContactTypeEnum $type, string $value): array
    {
        $query = Contact::query()
            ->where('contactable_type', 'user')
            ->where('type', $type->value)
            ->whereNotNull('verified_at');

        if ($type === ContactTypeEnum::TELEGRAM && str_starts_with($value, '@')) {
            $username = ltrim($value, '@');

            $query->where(function ($telegramQuery) use ($value, $username): void {
                $telegramQuery
                    ->where('value', $value)
                    ->orWhere('meta->username', $username);
            });
        } else {
            $query->where('value', $value);
        }

        return $query
            ->limit(2)
            ->pluck('contactable_id')
            ->map(fn ($userId): int => (int) $userId)
            ->unique()
            ->values()
            ->all();
    }
}
