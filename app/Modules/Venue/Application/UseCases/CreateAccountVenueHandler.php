<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Str;

final class CreateAccountVenueHandler
{
    /**
     * @param  array{name: string, type: string, description?: string|null, raw_address?: string|null}  $data
     */
    public function handle(User $user, array $data): Venue
    {
        if (!$user->isConfirmed()) {
            throw new \DomainException('Подтвердите аккаунт, чтобы добавить площадку!', 403);
        }

        return Venue::query()->create([
            'created_by_user_id' => $user->id,
            'name' => $data['name'],
            'alias' => $this->makeUniqueAlias($data['name']),
            'type' => VenueTypeEnum::from($data['type'])->value,
            'status' => VenueStatusEnum::UNCONFIRMED->value,
            'description' => $data['description'] ?? null,
            'raw_address' => $data['raw_address'] ?? null,
        ]);
    }

    private function makeUniqueAlias(string $name): string
    {
        $baseAlias = Str::slug($name);

        if ($baseAlias === '') {
            $baseAlias = 'venue';
        }

        $alias = $baseAlias;
        $counter = 2;

        while (Venue::query()->where('alias', $alias)->exists()) {
            $alias = "{$baseAlias}-{$counter}";
            $counter++;
        }

        return $alias;
    }
}
