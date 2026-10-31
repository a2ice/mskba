<?php

namespace App\Filament\Backoffice\Resources\Teams\RelationManagers;

use App\Filament\Backoffice\Resources\Teams\TeamResource;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class TournamentsRelationManager extends RelationManager
{
    protected static string $relationship = 'tournamentEntries';
    protected static ?string $title = 'Турниры';
    protected static string|\BackedEnum|null $icon = 'heroicon-o-flag';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return TeamResource::canView($ownerRecord);
    }

    public function form(Schema $schema): Schema { return $schema->components([]); }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('tournament.title')->label('Турнир')->searchable(),
            TextColumn::make('tournament.league.name')->label('Лига')->placeholder('Независимый'),
            TextColumn::make('tournament.leagueSeason.name')->label('Сезон')->placeholder('Без сезона'),
            TextColumn::make('tournament.starts_on')->label('Начало')->date('d.m.Y'),
            TextColumn::make('status')->label('Участие')->badge(),
            TextColumn::make('members_count')->label('Игроков')->counts('members'),
        ])->defaultSort('id', 'desc');
    }
}
