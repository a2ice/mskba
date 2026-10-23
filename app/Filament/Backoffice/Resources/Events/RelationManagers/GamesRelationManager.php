<?php

namespace App\Filament\Backoffice\Resources\Events\RelationManagers;

use App\Filament\Backoffice\Resources\Events\EventResource;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class GamesRelationManager extends RelationManager
{
    protected static string $relationship = 'games';
    protected static ?string $title = 'Связанные игры';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return EventResource::canView($ownerRecord);
    }

    public function form(Schema $schema): Schema { return $schema->components([]); }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID'),
            TextColumn::make('title')->label('Игра'),
            TextColumn::make('status')->label('Статус')->badge()->formatStateUsing(
                fn ($state): string => $state instanceof GameStatusEnum ? $state->label() : '—'),
            TextColumn::make('side_a_size')->label('Сторона A'),
            TextColumn::make('side_b_size')->label('Сторона Б'),
            TextColumn::make('scheduled_starts_at')->label('Начало')->dateTime('d.m.Y H:i'),
        ]);
    }
}
