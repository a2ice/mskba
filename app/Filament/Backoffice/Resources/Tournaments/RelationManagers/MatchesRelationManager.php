<?php

namespace App\Filament\Backoffice\Resources\Tournaments\RelationManagers;

use App\Filament\Backoffice\Resources\Tournaments\TournamentResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class MatchesRelationManager extends RelationManager
{
    protected static string $relationship = 'matches';
    protected static ?string $title = 'Матчи и результаты';
    protected static string|\BackedEnum|null $icon = 'heroicon-o-chart-bar';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return TournamentResource::canView($ownerRecord);
    }

    public function form(Schema $schema): Schema { return $schema->components([]); }

    public function table(Table $table): Table
    {
        // Results and confirmed statistics stay with the existing game lifecycle.
        return $table->columns([
            TextColumn::make('round')->label('Тур')->placeholder('—'),
            TextColumn::make('sequence')->label('Порядок')->sortable(),
            TextColumn::make('entryA.name')->label('Команда А'),
            TextColumn::make('entryB.name')->label('Команда Б'),
            TextColumn::make('game_id')->label('Игра ID')->placeholder('Ещё не создана'),
            TextColumn::make('game.status')->label('Статус игры')->placeholder('—'),
            TextColumn::make('game.scheduled_starts_at')->label('Дата')->dateTime('d.m.Y H:i')->placeholder('—'),
        ])->defaultSort('sequence');
    }
}
