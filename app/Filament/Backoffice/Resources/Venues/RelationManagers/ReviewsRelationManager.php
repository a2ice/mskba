<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueReview;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class ReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'reviews';

    protected static ?string $title = 'Отзывы';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Venue && VenueResource::canEdit($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.username')->label('Автор')->searchable(),
                TextColumn::make('rating')->label('Оценка'),
                TextColumn::make('body')->label('Отзыв')->wrap(),
                IconColumn::make('is_published')->label('Опубликован')->boolean(),
                TextColumn::make('created_at')->label('Создан')->dateTime(),
                TextColumn::make('updated_at')->label('Изменён')->dateTime(),
            ])
            ->filters([TernaryFilter::make('is_published')->label('Опубликован')])
            ->recordActions([
                Action::make('publish')->label('Опубликовать')->color('success')
                    ->visible(fn (VenueReview $record): bool => ! $record->is_published)
                    ->requiresConfirmation()
                    ->action(fn (VenueReview $record) => $this->moderate($record, true)),
                Action::make('unpublish')->label('Снять с публикации')->color('danger')
                    ->visible(fn (VenueReview $record): bool => $record->is_published)
                    ->requiresConfirmation()
                    ->action(fn (VenueReview $record) => $this->moderate($record, false)),
            ]);
    }

    private function moderate(VenueReview $record, bool $publish): void
    {
        $venue = $this->getOwnerRecord();
        abort_unless($venue instanceof Venue && VenueResource::canEdit($venue)
            && (int) $record->venue_id === (int) $venue->id, 403);
        // Do not approve a revision that changed while the moderator viewed it.
        \Illuminate\Support\Facades\DB::transaction(function () use ($venue, $record, $publish): void {
            $current = $venue->reviews()->lockForUpdate()->findOrFail($record->id);
            abort_unless($current->updated_at?->equalTo($record->updated_at)
                && $current->body === $record->body && $current->rating === $record->rating, 409);
            $current->update(['is_published' => $publish, 'published_at' => $publish ? now() : null]);
        });
    }
}
