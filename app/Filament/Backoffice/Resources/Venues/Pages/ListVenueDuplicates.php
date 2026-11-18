<?php

namespace App\Filament\Backoffice\Resources\Venues\Pages;

use App\Filament\Backoffice\Resources\Venues\VenueDuplicateResource;
use App\Modules\Venue\Application\UseCases\MergeVenueDuplicateHandler;
use App\Modules\Venue\Application\Services\VenueDuplicateContractRights;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueDuplicate;
use App\Modules\Venue\Domain\Enums\VenueDuplicateMatchTypeEnum;
use App\Modules\Venue\Domain\Enums\VenueDuplicateStatusEnum;
use Illuminate\Support\Facades\DB;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;

final class ListVenueDuplicates extends ListRecords
{
    protected static string $resource = VenueDuplicateResource::class;

    private static function venueOptions(string $search): array
    {
        $query = Venue::query();
        $search = trim($search);
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%');
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        return $query->orderBy('name')->limit(30)->get()
            ->mapWithKeys(fn (Venue $venue): array => [$venue->id => self::formatVenue($venue)])
            ->all();
    }

    private static function venueLabel(mixed $id): ?string
    {
        $venue = Venue::query()->find((int) $id);
        return $venue === null ? null : self::formatVenue($venue);
    }

    private static function formatVenue(Venue $venue): string
    {
        return $venue->name.' (#'.$venue->id.', '.$venue->status->label().')'
            .($venue->raw_address ? ' — '.$venue->raw_address : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('markDuplicate')
                ->label('Пометить как дубль')
                ->icon('heroicon-o-document-duplicate')
                ->visible(fn (): bool => VenueDuplicateResource::canViewAny())
                ->schema([
                    Select::make('venue_a_id')
                        ->label('Первая площадка')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::venueOptions($search))
                        ->getOptionLabelUsing(fn ($value): ?string => self::venueLabel($value))
                        ->helperText('Найди площадку по названию или ID. Статус подтверждения не меняется.'),
                    Select::make('venue_b_id')
                        ->label('Вторая площадка — предполагаемый дубль')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::venueOptions($search))
                        ->getOptionLabelUsing(fn ($value): ?string => self::venueLabel($value)),
                ])
                ->requiresConfirmation()
                ->modalDescription('Создаётся кандидат на объединение. Никакие площадки пока не скрываются, не удаляются и не меняют статус. После этого выбери основную площадку через «Объединить».')
                ->action(function (array $data): void {
                    abort_unless(VenueDuplicateResource::canViewAny(), 403);
                    $a = (int) ($data['venue_a_id'] ?? 0);
                    $b = (int) ($data['venue_b_id'] ?? 0);
                    if ($a <= 0 || $b <= 0 || $a === $b) {
                        Notification::make()->danger()->title('Выбери две разные площадки.')->send();
                        return;
                    }

                    [$first, $second] = [min($a, $b), max($a, $b)];
                    try {
                        DB::transaction(function () use ($first, $second): void {
                            $venues = Venue::query()->whereIn('id', [$first, $second])
                                ->lockForUpdate()->get();
                            if ($venues->count() !== 2 || $venues->contains(fn (Venue $venue): bool => $venue->canonical_venue_id !== null)) {
                                throw new \InvalidArgumentException('Обе площадки должны существовать и ещё не быть назначенными дублями.');
                            }

                            $candidate = VenueDuplicate::query()
                                ->where('venue_id', $first)
                                ->where('duplicate_venue_id', $second)
                                ->lockForUpdate()->first();

                            if ($candidate !== null && $candidate->status === VenueDuplicateStatusEnum::MERGED) {
                                throw new \InvalidArgumentException('Эта пара уже объединена.');
                            }

                            VenueDuplicate::query()->updateOrCreate([
                                'venue_id' => $first,
                                'duplicate_venue_id' => $second,
                            ], [
                                'matched_by' => VenueDuplicateMatchTypeEnum::MANUAL,
                                'status' => VenueDuplicateStatusEnum::PENDING,
                                'score' => 100,
                                'metadata' => [
                                    'created_manually' => true,
                                    'marked_by_user_id' => auth('web')->id(),
                                ],
                                'resolved_at' => null,
                                'resolved_by' => null,
                            ]);
                        });
                        Notification::make()->success()->title('Кандидат дубля создан')
                            ->body('Теперь можно выбрать основную площадку и выполнить объединение.')
                            ->send();
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
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
                        ->live()
                        ->options(fn (Get $get): array => Venue::query()
                            ->whereIn('id', (array) ($get('venue_ids') ?? []))
                            ->get()->mapWithKeys(
                                fn (Venue $venue): array => [$venue->id => $venue->name.' (#'.$venue->id.')']
                            )->all()),
                    Select::make('contract_resolution')->label('Договорные права при объединении')
                        ->required()->default(VenueDuplicateContractRights::KEEP)
                        ->options([
                            VenueDuplicateContractRights::KEEP => 'Не изменять права (отказать при действующих)',
                            VenueDuplicateContractRights::TRANSFER => 'Перенести действующие роли дублей на основную',
                            VenueDuplicateContractRights::RETIRE => 'Сохранить права основной, завершить действующие роли дублей',
                        ])
                        ->helperText(function (Get $get): string {
                            $main = (int) $get('canonical_venue_id');
                            $ids = array_filter(array_map('intval', (array) ($get('venue_ids') ?? [])),
                                fn (int $id): bool => $id !== $main);
                            if ($main <= 0 || $ids === []) {
                                return 'Выбери основную площадку и дубли для просмотра договорных прав.';
                            }

                            return implode(' ', array_map(
                                fn (int $id): string => app(VenueDuplicateContractRights::class)->summary($main, $id),
                                array_values($ids),
                            )).' Перенос и завершение доступны только суперадминистратору.';
                        }),
                ])->requiresConfirmation()
                ->action(function (array $data): void {
                    abort_unless(VenueDuplicateResource::canViewAny(), 403);
                    $ids = collect($data['venue_ids'] ?? [])->map('intval')->unique()->values()->all();
                    abort_unless(count($ids) >= 2 && in_array((int) $data['canonical_venue_id'], $ids, true), 422);
                    try {
                        app(MergeVenueDuplicateHandler::class)->handleBatch(
                            $ids, (int) $data['canonical_venue_id'], auth('web')->user(),
                            (string) ($data['contract_resolution'] ?? VenueDuplicateContractRights::KEEP));
                        Notification::make()->success()->title('Группа дублей объединена')->send();
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ];
    }
}
