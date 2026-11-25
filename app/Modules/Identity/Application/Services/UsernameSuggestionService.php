<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Exceptions\InvalidIdentityValueException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\ValueObjects\UsernameVO;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UsernameSuggestionService
{
    private const FIRST_SUFFIX = 777;

    private const BATCH_SIZE = 100;

    private const MAX_SUFFIX_ATTEMPTS = 10000;

    /**
     * Returns a suggestion, not a reservation. The accepting/creating
     * action must validate the final value again before persisting it.
     */
    public function suggest(string $email): string
    {
        $local = explode('@', trim($email), 2)[0];
        $base = strtolower($local);
        $base = preg_replace('/[^a-z0-9._-]+/', '_', $base) ?? '';
        $base = trim($base, '._-');
        $base = preg_replace('/[0-9]+$/', '', $base) ?? '';
        $base = trim($base, '._-');
        $base = rtrim(substr($base, 0, UsernameVO::MAX_LENGTH), '._-');

        if (strlen($base) < UsernameVO::MIN_LENGTH) {
            $base = 'user';
        } elseif ($this->isAvailable($base)) {
            return $base;
        }

        for ($offset = 0; $offset < self::MAX_SUFFIX_ATTEMPTS; $offset += self::BATCH_SIZE) {
            $candidates = [];
            for ($index = 0; $index < self::BATCH_SIZE; $index++) {
                $suffix = (string) (self::FIRST_SUFFIX + $offset + $index);
                $prefix = rtrim(substr($base, 0, UsernameVO::MAX_LENGTH - strlen($suffix)), '._-');
                $candidates[] = UsernameVO::fromString($prefix.$suffix)->value;
            }

            $occupied = $this->occupied($candidates);
            foreach ($candidates as $candidate) {
                if (! isset($occupied[$candidate])) {
                    return $candidate;
                }
            }
        }

        throw ValidationException::withMessages([
            'username' => 'Не удалось подобрать свободный логин. Укажите другой логин.',
        ]);
    }

    public function isAvailable(string $username): bool
    {
        try {
            $username = UsernameVO::fromString($username)->value;
        } catch (InvalidIdentityValueException) {
            return false;
        }

        return ! isset($this->occupied([$username])[$username]);
    }

    /** @param list<string> $candidates
     * @return array<string, true>
     */
    private function occupied(array $candidates): array
    {
        $occupied = [];

        // Public profile identifiers share a namespace. Deleted and merged
        // physical identities also retain their identifiers.
        $rows = User::withTrashed()
            ->where(function ($query) use ($candidates): void {
                $query->whereIn(DB::raw('LOWER(username)'), $candidates)
                    ->orWhereIn(DB::raw('LOWER(nickname)'), $candidates);
            })
            ->get(['username', 'nickname']);

        foreach ($rows as $row) {
            foreach ([$row->username, $row->nickname] as $value) {
                if (is_string($value) && $value !== '') {
                    $occupied[strtolower($value)] = true;
                }
            }
        }

        return $occupied;
    }
}
