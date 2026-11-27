<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

abstract class VenueContractsRelationManager extends RelationManager
{
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $actor = auth('web')->user()?->canonical()->fresh();

        return $ownerRecord instanceof Venue && VenueResource::authorized()
            && $actor !== null && $actor->isConfirmed() && ! $actor->isBlocked()
            && ! $actor->trashed() && $actor->system_role === UserSystemRoleEnum::SUPERADMIN;
    }

    protected function actor(): User
    {
        $actor = auth('web')->user()?->canonical()->fresh();
        abort_unless($actor !== null && static::canViewForRecord($this->getOwnerRecord(), $this->pageClass), 403);

        return $actor;
    }

    protected function mutableVenue(): bool
    {
        $venue = $this->getOwnerRecord();

        return ! $venue->trashed() && $venue->canonical_venue_id === null && $venue->allowsOperationalChanges();
    }

    protected function candidateField(bool $staffOnly = false): Select
    {
        $query = fn () => User::query()->where('status', UserStatusEnum::CONFIRMED->value)
            ->whereNull('canonical_user_id')->when($staffOnly, fn ($q) => $q->whereIn('system_role', [
                UserSystemRoleEnum::MANAGER->value, UserSystemRoleEnum::ADMIN->value, UserSystemRoleEnum::SUPERADMIN->value,
            ]));

        return Select::make('user_id')->label('Пользователь')->required()->searchable()
            ->getSearchResultsUsing(fn (string $search): array => $query()
                ->where('username', 'like', '%'.$search.'%')->orderBy('username')->limit(25)->pluck('username','id')->all())
            ->getOptionLabelUsing(fn ($value): ?string => $query()->find($value)?->username);
    }

    protected function perform(callable $operation, string $success): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Не удалось выполнить действие')
                ->body(collect($exception->errors())->flatten()->first())->persistent()->send();
            throw $exception;
        }

        Notification::make()->success()->title($success)->send();
    }
}
