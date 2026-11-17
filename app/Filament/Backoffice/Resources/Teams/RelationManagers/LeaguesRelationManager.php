<?php

namespace App\Filament\Backoffice\Resources\Teams\RelationManagers;

use App\Filament\Backoffice\Resources\Teams\TeamResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class LeaguesRelationManager extends RelationManager
{
    protected static string $relationship = 'leagues';
    protected static ?string $title = 'Лиги';
    protected static string|\BackedEnum|null $icon = 'heroicon-o-trophy';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return TeamResource::canView($ownerRecord);
    }

    public function form(Schema $schema): Schema { return $schema->components([]); }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('name')->label('Лига')->searchable(),
            TextColumn::make('status')->label('Статус')->badge(),
            TextColumn::make('owner.username')->label('Владелец'),
        ])->defaultSort('id', 'desc');
    }
}
