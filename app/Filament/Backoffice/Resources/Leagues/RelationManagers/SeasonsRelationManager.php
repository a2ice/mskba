<?php

namespace App\Filament\Backoffice\Resources\Leagues\RelationManagers;

use App\Filament\Backoffice\Resources\Leagues\LeagueResource;
use App\Filament\Backoffice\Resources\Seasons\BackofficeSeasonEditor;
use App\Filament\Backoffice\Resources\Seasons\SeasonResource;
use App\Modules\League\Domain\Models\League;
use App\Modules\League\Domain\Models\LeagueSeason;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class SeasonsRelationManager extends RelationManager
{
    protected static string $relationship = 'seasons';

    protected static ?string $title = 'Сезоны';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-calendar-days';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof League
            && LeagueResource::canView($ownerRecord)
            && SeasonResource::canViewAny();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components($this->seasonFields());
    }

    /**
     * The league is always taken from the parent record, never from user input.
     * Team choices only come from that league, with server-side validation in
     * BackofficeSeasonEditor for every create/update.
     */
    private function seasonFields(): array
    {
        $fields = [
            TextInput::make('name')->label('Название сезона')->required()->minLength(2)->maxLength(150),
            Select::make('status')->label('Статус')->required()
                ->options(['draft' => 'Черновик', 'active' => 'Активный', 'archived' => 'В архиве'])
                ->default('draft'),
            DatePicker::make('starts_on')->label('Начало')->native(false),
            DatePicker::make('ends_on')->label('Окончание')->native(false)->afterOrEqual('starts_on'),
            Textarea::make('description')->label('Описание')->rows(4)->maxLength(10000)->columnSpanFull(),
        ];

        if (SeasonResource::canManageTeams()) {
            $fields[] = Select::make('team_ids')->label('Команды сезона')->multiple()->searchable()->preload()
                ->options(fn (): array => $this->getOwnerRecord()->teams()
                    ->orderBy('teams.name')->pluck('teams.name', 'teams.id')->all())
                ->helperText('Сначала добавьте команды на вкладке «Команды» самой лиги.')
                ->default([])
                ->columnSpanFull();
        }

        return $fields;
    }

    private function assertBelongsToLeague(LeagueSeason $record): void
    {
        abort_unless($record->league_id === $this->getOwnerRecord()->getKey(), 404);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')->label('Сезон')->searchable()->sortable(),
                TextColumn::make('starts_on')->label('Начало')->date('d.m.Y')->placeholder('—'),
                TextColumn::make('ends_on')->label('Окончание')->date('d.m.Y')->placeholder('—'),
                TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn (string $state): string => [
                        'draft' => 'Черновик', 'active' => 'Активный', 'archived' => 'В архиве',
                    ][$state] ?? $state),
                TextColumn::make('teams_count')->label('Команд')->counts('teams'),
                TextColumn::make('tournaments_count')->label('Турниров')->counts('tournaments'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Статус')
                    ->options(['draft' => 'Черновик', 'active' => 'Активный', 'archived' => 'В архиве']),
            ])
            ->headerActions([
                Action::make('createSeason')
                    ->label('Создать сезон')->icon('heroicon-o-plus')
                    ->visible(fn (): bool => SeasonResource::canCreate()
                        && $this->getOwnerRecord()->status !== 'archived')
                    ->modalHeading('Новый сезон лиги «'.$this->getOwnerRecord()->name.'»')
                    ->schema($this->seasonFields())
                    ->action(function (array $data): void {
                        abort_unless(SeasonResource::canCreate(), 403);
                        $data['league_id'] = $this->getOwnerRecord()->getKey();
                        app(BackofficeSeasonEditor::class)->create($data);
                        Notification::make()->success()->title('Сезон создан')->send();
                    }),
            ])
            ->recordActions([
                Action::make('editSeason')
                    ->label('Редактировать')->icon('heroicon-o-pencil-square')
                    ->visible(fn (LeagueSeason $record): bool => SeasonResource::canEdit($record))
                    ->fillForm(fn (LeagueSeason $record): array => [
                        'name' => $record->name,
                        'status' => $record->status,
                        'starts_on' => $record->starts_on?->format('Y-m-d'),
                        'ends_on' => $record->ends_on?->format('Y-m-d'),
                        'description' => $record->description,
                        'team_ids' => SeasonResource::canManageTeams()
                            ? $record->teams()->pluck('teams.id')->all() : [],
                    ])
                    ->schema($this->seasonFields())
                    ->action(function (LeagueSeason $record, array $data): void {
                        $this->assertBelongsToLeague($record);
                        app(BackofficeSeasonEditor::class)->update($record, $data);
                        Notification::make()->success()->title('Сезон обновлён')->send();
                    }),
                Action::make('archiveSeason')
                    ->label('Архивировать')->icon('heroicon-o-archive-box')
                    ->requiresConfirmation()
                    ->visible(fn (LeagueSeason $record): bool =>
                        $record->status !== 'archived' && SeasonResource::canArchive())
                    ->action(function (LeagueSeason $record): void {
                        $this->assertBelongsToLeague($record);
                        app(BackofficeSeasonEditor::class)->archive($record);
                        Notification::make()->success()->title('Сезон архивирован')->send();
                    }),
                Action::make('deleteSeason')
                    ->label('Удалить')->icon('heroicon-o-trash')->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Связанный с командами, турнирами или играми сезон удалить нельзя. Для истории используйте архивирование.')
                    ->visible(fn (LeagueSeason $record): bool => SeasonResource::canDelete($record))
                    ->action(function (LeagueSeason $record): void {
                        $this->assertBelongsToLeague($record);
                        app(BackofficeSeasonEditor::class)->delete($record);
                        Notification::make()->success()->title('Сезон удалён')->send();
                    }),
            ]);
    }
}
