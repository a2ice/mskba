<?php

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Application\Contracts\UserReadRepositoryContract;
use App\Modules\Identity\Domain\Models\User;

class EloquentUserReadRepository implements UserReadRepositoryContract
{
    public function findByResolvedLogin(string $normalizedLogin, bool $isContact): ?User
    {
        if ($isContact) {
            $contact = Contact::query()
                ->where('entity_type', 'user')
                ->whereRaw('LOWER(value) = ?', [$normalizedLogin])
                ->orderByDesc('id')
                ->first();

            if ($contact === null) {
                return null;
            }

            return User::query()
                ->whereKey($contact->entity_id)
                ->with(['contacts' => function ($query) use ($contact) {
                    $query->whereKey($contact->id);
                }])
                ->first();
        }

        return User::query()
            ->whereRaw('LOWER(login) = ?', [$normalizedLogin])
            ->first();
    }

    public function findById(int $userId): ?User
    {
        return User::query()->find($userId);
    }
}
