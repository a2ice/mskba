<?php

namespace App\Filament\Backoffice\Resources\Teams\RelationManagers;

use App\Filament\Backoffice\Resources\Teams\TeamResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class SeasonsRelationManager extends RelationManager
{
    protected static string $relationship = 'leagueSeasons';
    protected static ?string $title = 'Сезоны';
    protected static string|\BackedEnum|null $icon = 'heroicon-o-calendar-days';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return TeamResource::canView($ownerRecord);
    }

    public function form(Schema $schema): Schema { return $schema->components([]); }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('name')->label('Сезон')->searchable(),
            TextColumn::make('league.name')->label('Лига'),
            TextColumn::make('status')->label('Статус')->badge(),
            TextColumn::make('starts_on')->label('Начало')->date('d.m.Y')->placeholder('Без даты'),
            TextColumn::make('ends_on')->label('Конец')->date('d.m.Y')->placeholder('Без даты'),
        ])->defaultSort('id', 'desc');
    }
}
