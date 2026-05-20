<?php

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Contact\Domain\Enums\ContactStatusEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Application\Contracts\UserReadRepositoryContract;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Application\DTO\UserQueryFiltersDTO;

class EloquentUserReadRepository implements UserReadRepositoryContract
{
    public function getAllUsers(?UserQueryFiltersDTO $filters): array
    {
        $query = User::query();

        $sortBy = 'id';
        $sortDirection = 'asc';

        $perPage = 10;
        $page = 1;

        if ($filters !== null){
            if ($filters->status !== null) {
                $query->where('status', $filters->status->value);
            }

            if ($filters->systemRole !== null) {
                $query->where('system_role', $filters->systemRole->value);
            }

            if ($filters->includeProfile) {
                $query->with('profile');
            }

            if ($filters->includeContacts) {
                $query->with('contacts');
            }

            if ($filters->includeParticipationRoles) {
                $query->with('participationRoles');
            }

            if($filters->sortBy !== null) {
                $sortBy = $filters->sortBy;
            }

            if($filters->sortDirection !== null) {
                $sortDirection = $filters->sortDirection;
            }

            if($filters->perPage !== null) {
                $perPage = $filters->perPage;
            }

            if($filters->page !== null) {
                $page = $filters->page;
            }

        }

        $query->orderBy($sortBy, $sortDirection);

        $query->skip(($page - 1) * $perPage)->take($perPage);

        $result = $query->get()->all();

        return $result;
    }

    public function findByResolvedLogin(string $normalizedLogin, bool $isContact): ?User
    {
        if ($isContact) {
            $contact = Contact::query()
                ->where('entity_type', 'user')
                ->where('status', ContactStatusEnum::VERIFIED->value)
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

    public function findByLoginOrContact(string $normalizedLogin, bool $isContact): ?User
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
