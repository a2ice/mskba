<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Str;

final class NicknameSuggestionService
{
    private const NEUTRAL_BASE = 'court_king';

    public function suggest(User $user): string
    {
        $user = $user->canonical();
        $user->loadMissing('profile');

        $personal = $this->personalCandidate($user);
        if ($personal !== null && $this->isAvailableFor($user, $personal)) {
            return $personal;
        }

        return $this->neutralCandidate($user);
    }

    public function isAvailableFor(User $user, string $candidate): bool
    {
        $candidate = strtolower(trim($candidate));
        if (! preg_match('/^[a-z][a-z0-9_]{2,29}$/', $candidate)) {
            return false;
        }

        return ! User::withTrashed()
            ->whereNotIn('id', $user->canonical()->identityIds())
            ->where(function ($query) use ($candidate): void {
                $query->whereRaw('LOWER(nickname) = ?', [$candidate])
                    ->orWhereRaw('LOWER(username) = ?', [$candidate]);
            })
            ->exists();
    }

    private function personalCandidate(User $user): ?string
    {
        $parts = collect([
            $user->profile?->first_name,
            $user->profile?->last_name,
        ])->map(fn (?string $value): string => $this->slugPart($value))
            ->filter()
            ->values();

        if ($parts->isEmpty()) {
            return null;
        }

        $candidate = rtrim(substr($parts->implode('_'), 0, 30), '_');

        return preg_match('/^[a-z][a-z0-9_]{2,29}$/', $candidate) ? $candidate : null;
    }

    private function slugPart(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        return strtolower(Str::slug(trim($value), '_'));
    }

    private function neutralCandidate(User $user): string
    {
        $user = $user->canonical();
        $occupied = [];

        $rows = User::withTrashed()
            ->whereNotIn('id', $user->identityIds())
            ->where(function ($query): void {
                $query->whereRaw('LOWER(nickname) LIKE ?', [self::NEUTRAL_BASE.'%'])
                    ->orWhereRaw('LOWER(username) LIKE ?', [self::NEUTRAL_BASE.'%']);
            })
            ->get(['nickname', 'username']);

        foreach ($rows as $row) {
            foreach ([$row->nickname, $row->username] as $value) {
                if (is_string($value) && $value !== '') {
                    $occupied[strtolower($value)] = true;
                }
            }
        }

        if (! isset($occupied[self::NEUTRAL_BASE])) {
            return self::NEUTRAL_BASE;
        }

        for ($suffix = 1; $suffix <= 999; $suffix++) {
            $candidate = self::NEUTRAL_BASE.$suffix;
            if (! isset($occupied[$candidate])) {
                return $candidate;
            }
        }

        for ($suffix = 1000; $suffix <= 9999; $suffix++) {
            $candidate = self::NEUTRAL_BASE.'_'.$suffix;
            if (! isset($occupied[$candidate])) {
                return $candidate;
            }
        }

        $suffix = max(10000, (int) $user->getKey());
        do {
            $candidate = self::NEUTRAL_BASE.'_'.$suffix++;
        } while (isset($occupied[$candidate]));

        return $candidate;
    }
}
