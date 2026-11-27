<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueReview;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                    ->schema([
                        Hidden::make('revision')->required(),
                        TextInput::make('review_rating')->label('Оценка')->readOnly()->dehydrated(false),
                        Textarea::make('review_body')->label('Отзыв')->readOnly()->dehydrated(false),
                    ])
                    ->fillForm(fn (VenueReview $record): array => [
                        'revision' => $this->revision($record),
                        'review_rating' => $record->rating,
                        'review_body' => $record->body,
                    ])
                    ->action(fn (VenueReview $record, array $data) => $this->moderate($record, true, $data['revision'])),
                Action::make('unpublish')->label('Снять с публикации')->color('danger')
                    ->visible(fn (VenueReview $record): bool => $record->is_published)
                    ->requiresConfirmation()
                    ->action(fn (VenueReview $record) => $this->moderate($record, false)),
            ]);
    }

    private function revision(VenueReview $record): string
    {
        return hash('sha256', serialize([$record->id, $record->rating, $record->body, $record->getRawOriginal('updated_at')]));
    }

    private function moderate(VenueReview $record, bool $publish, ?string $revision = null): void
    {
        $venue = $this->getOwnerRecord();
        abort_unless($venue instanceof Venue && VenueResource::canEdit($venue)
            && (int) $record->venue_id === (int) $venue->id, 403);
        // Do not approve a revision that changed while the moderator viewed it.
        \Illuminate\Support\Facades\DB::transaction(function () use ($venue, $record, $publish, $revision): void {
            $current = $venue->reviews()->lockForUpdate()->findOrFail($record->id);
            abort_if($publish && ($revision === null || ! hash_equals($this->revision($current), $revision)), 409, 'Отзыв изменился. Открой его заново.');
            $current->update(['is_published' => $publish, 'published_at' => $publish ? now() : null]);
        });
    }
}
