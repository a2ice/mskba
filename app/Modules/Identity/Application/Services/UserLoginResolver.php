<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Domain\ValueObjects\ContactValue;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class UserLoginResolver
{
    public function resolve(string $login): ?User
    {
        return $this->resolveCandidates($login)->first();
    }

    /**
     * Returns physical accounts that actually match the supplied login, but
     * only when all of them belong to one canonical identity. Password checks
     * stay in AuthHandler and are performed against these physical accounts.
     *
     * @return Collection<int, User>
     */
    public function resolveCandidates(string $login): Collection
    {
        $login = trim($login);
        $userIds = [];

        $usernameUser = User::query()->where('username', $login)->first();
        if ($usernameUser !== null) {
            $userIds[] = (int) $usernameUser->getKey();
        }

        foreach ($this->contactCandidates($login) as [$type, $value]) {
            array_push($userIds, ...$this->contactUserIds($type, $value));
        }

        $userIds = array_values(array_unique($userIds));
        if ($userIds === []) {
            return collect();
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->sortBy(fn (User $user): int => array_search((int) $user->id, $userIds, true))
            ->values();

        if ($users->count() !== count($userIds)) {
            return collect();
        }

        $canonicalIds = $users
            ->map(fn (User $user): int => (int) $user->canonical()->id)
            ->unique()
            ->values();

        return $canonicalIds->count() === 1 ? $users : collect();
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
            ->distinct()
            ->pluck('contactable_id')
            ->map(fn ($userId): int => (int) $userId)
            ->unique()
            ->values()
            ->all();
    }
}
