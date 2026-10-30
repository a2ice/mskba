<?php

namespace App\Filament\Backoffice\Resources\Teams\RelationManagers;

use App\Filament\Backoffice\Resources\Teams\TeamResource;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\GameSide;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class GamesRelationManager extends RelationManager
{
    protected static string $relationship = 'gameSides';
    protected static ?string $title = 'Игры и статистика';
    protected static string|\BackedEnum|null $icon = 'heroicon-o-chart-bar';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return TeamResource::canView($ownerRecord);
    }

    public function form(Schema $schema): Schema { return $schema->components([]); }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('game.title')->label('Игра')->searchable()->placeholder('—'),
            TextColumn::make('game.status')->label('Статус')->badge()
                ->formatStateUsing(fn ($state): string => $state instanceof GameStatusEnum
                    ? $state->label() : '—'),
            TextColumn::make('game.scheduled_starts_at')->label('Дата')->dateTime('d.m.Y H:i'),
            TextColumn::make('display_name')->label('Название стороны'),
            TextColumn::make('score')->label('Очки')->placeholder('—'),
            TextColumn::make('individual_stats')->label('Записей статистики')
                ->state(fn (GameSide $record): int => $record->playerStatistics()->count()),
            TextColumn::make('game.statistics_status')->label('Статистика')
                ->formatStateUsing(fn ($state): string => $state?->label() ?? '—'),
        ])->defaultSort('id', 'desc');
        // Intentionally read-only: game results and verified statistics must be
        // edited only through the event/game lifecycle, never from a team card.
    }
}
