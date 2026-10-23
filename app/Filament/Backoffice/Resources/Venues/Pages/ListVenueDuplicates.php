<?php

namespace App\Filament\Backoffice\Resources\Venues\Pages;

use App\Filament\Backoffice\Resources\Venues\VenueDuplicateResource;
use App\Modules\Venue\Application\UseCases\MergeVenueDuplicateHandler;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;

final class ListVenueDuplicates extends ListRecords
{
    protected static string $resource = VenueDuplicateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('mergeGroup')->label('Объединить группу дублей')
                ->icon('heroicon-o-arrows-pointing-in')->color('warning')
                ->visible(fn (): bool => VenueDuplicateResource::canViewAny())
                ->schema([
                    Select::make('venue_ids')->label('Площадки одной группы')
                        ->multiple()->required()->searchable()->live()
                        ->getSearchResultsUsing(fn (string $search): array => Venue::query()
                            ->where('name', 'like', '%'.$search.'%')->orderBy('name')
                            ->limit(30)->get()->mapWithKeys(
                                fn (Venue $venue): array => [$venue->id => $venue->name.' (#'.$venue->id.')']
                            )->all())
                        ->getOptionLabelsUsing(fn (array $values): array => Venue::query()
                            ->whereIn('id', $values)->get()->mapWithKeys(
                                fn (Venue $venue): array => [$venue->id => $venue->name.' (#'.$venue->id.')']
                            )->all())
                        ->helperText('Выбери минимум две площадки, связанные как кандидаты дублей.'),
                    Select::make('canonical_venue_id')->label('Основная площадка')->required()
                        ->options(fn (Get $get): array => Venue::query()
                            ->whereIn('id', (array) ($get('venue_ids') ?? []))
                            ->get()->mapWithKeys(
                                fn (Venue $venue): array => [$venue->id => $venue->name.' (#'.$venue->id.')']
                            )->all()),
                ])->requiresConfirmation()
                ->action(function (array $data): void {
                    abort_unless(VenueDuplicateResource::canViewAny(), 403);
                    $ids = collect($data['venue_ids'] ?? [])->map('intval')->unique()->values()->all();
                    abort_unless(count($ids) >= 2 && in_array((int) $data['canonical_venue_id'], $ids, true), 422);
                    try {
                        app(MergeVenueDuplicateHandler::class)->handleBatch(
                            $ids, (int) $data['canonical_venue_id'], auth('web')->user());
                        Notification::make()->success()->title('Группа дублей объединена')->send();
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ];
    }
}
