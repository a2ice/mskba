<?php

namespace App\Filament\Backoffice\Resources\Tournaments\RelationManagers;

use App\Filament\Backoffice\Resources\Tournaments\TournamentResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';
    protected static ?string $title = 'Участники';
    protected static string|\BackedEnum|null $icon = 'heroicon-o-users';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return TournamentResource::canView($ownerRecord);
    }

    public function form(Schema $schema): Schema { return $schema->components([]); }

    public function table(Table $table): Table
    {
        // Read-only: admissions must be processed through the tournament lifecycle.
        return $table->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('name')->label('Участник / команда')->searchable(),
            TextColumn::make('team.name')->label('Команда')->placeholder('Сборная'),
            TextColumn::make('status')->label('Статус')->badge(),
            TextColumn::make('seed')->label('Посев')->placeholder('—'),
            TextColumn::make('members_count')->label('Игроков')->counts('members'),
        ])->defaultSort('id', 'desc');
    }
}
