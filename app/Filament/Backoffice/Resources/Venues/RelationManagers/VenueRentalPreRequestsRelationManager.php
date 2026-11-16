<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Modules\VenueBooking\Application\Services\VenueBookingBackofficeAccess;
use App\Modules\VenueBooking\Domain\Models\VenueRentalPreRequest;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class VenueRentalPreRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'rentalPreRequests';
    protected static ?string $title = 'Предзаявки без мероприятия';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return app(VenueBookingBackofficeAccess::class)->canView(auth('web')->user());
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('№'),
            TextColumn::make('requester.username')->label('Заявитель'),
            TextColumn::make('court.name')->label('Зал'),
            TextColumn::make('starts_at')->label('Начало')->dateTime(),
            TextColumn::make('ends_at')->label('Окончание')->dateTime(),
            TextColumn::make('status')->label('Статус')->formatStateUsing(fn ($state) => $state->label()),
            TextColumn::make('created_at')->label('Создана')->dateTime(),
        ])->defaultSort('id', 'desc')->recordActions([
            Action::make('conversation')->label('Переписка')->icon('heroicon-o-chat-bubble-left-right')
                ->url(fn (VenueRentalPreRequest $record) => route('account.venue-pre-requests.show', $record)),
        ]);
    }
}
