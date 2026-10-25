<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Venue\Domain\Enums\VenueCourtEnvironmentEnum;
use App\Modules\Venue\Domain\Enums\VenueSurfaceTypeEnum;
use App\Modules\Venue\Application\Services\VenueCourtGalleryManager;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\HtmlString;

final class CourtsRelationManager extends RelationManager
{
    protected static string $relationship = 'courts';
    protected static ?string $title = 'Игровые зоны / залы';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return VenueResource::authorized() && ! $ownerRecord->trashed();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
            Section::make('Игровая зона')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(120),
                TextInput::make('alias')->label('URL-алиас')->required()->maxLength(120)
                    ->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D')
                    ->rule(fn (?VenueCourt $record) => Rule::unique('venue_courts', 'alias')
                        ->where('venue_id', $this->getOwnerRecord()->getKey())->ignore($record?->getKey())),
                TextInput::make('sort_order')->label('Порядок')->numeric()->minValue(0)->maxValue(65535)->default(10),
                Select::make('environment')->label('Тип зала / зоны')->options(
                    collect(VenueCourtEnvironmentEnum::cases())->mapWithKeys(fn ($case): array =>
                        [$case->value => $case->label()])->all()
                )->required(),
                Select::make('hoops_count')->label('Колец')->options([1 => '1', 2 => '2']),
                Select::make('surface_type')->label('Покрытие')->options(
                    collect(VenueSurfaceTypeEnum::cases())->mapWithKeys(fn ($case): array =>
                        [$case->value => $case->label()])->all()
                ),
                Toggle::make('supports_halves')->label('Разделяется на половины')->default(false),
                Toggle::make('allows_whole')->label('Бронирование целиком')->default(true),
                Toggle::make('allows_halves')->label('Бронирование половин')->default(false)
                    ->helperText('Включайте только для зоны с двумя кольцами и поддержкой половин.'),
            ]),
            Section::make('Медиатека')->description('Фотографии именно этого зала / зоны')
                ->schema([
                    Placeholder::make('court_photo_preview')->label('Фотографии')
                        ->content(fn (?VenueCourt $record): HtmlString => $this->courtPhotoPreview($record)),
                ]),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Зал / зона')->searchable(),
            ImageColumn::make('cover')->label('Фото')->getStateUsing(
                fn (VenueCourt $record): ?string => ($photo = $record->media()
                    ->where('collection', 'gallery')->orderByDesc('is_featured')
                    ->orderBy('sort_order')->first()) ? url($photo->publicUrl()) : null
            )->square()->height(48),
            TextColumn::make('photo_count')->label('Фотографий')->getStateUsing(
                fn (VenueCourt $record): int => $record->media()->where('collection', 'gallery')->count()
            ),
            TextColumn::make('alias')->label('Код'),
            TextColumn::make('environment')->label('Тип зала / зоны')
                ->formatStateUsing(fn ($state): string => $state instanceof VenueCourtEnvironmentEnum
                    ? $state->label() : (VenueCourtEnvironmentEnum::tryFrom((string) $state)?->label() ?? 'Не указан')),
            IconColumn::make('is_primary')->label('Основная')->boolean(),
            TextColumn::make('hoops_count')->label('Колец'),
            TextColumn::make('surface_type')->label('Покрытие')
                ->formatStateUsing(fn ($state): string => $state instanceof VenueSurfaceTypeEnum
                    ? $state->label() : (VenueSurfaceTypeEnum::tryFrom((string) $state)?->label() ?? '—')),
            IconColumn::make('allows_whole')->label('Целиком')->boolean(),
            IconColumn::make('allows_halves')->label('Половины')->boolean(),
        ])->headerActions([
            CreateAction::make()->label('Добавить зал')
                ->visible(fn (): bool => VenueResource::authorized()),
        ])->recordActions([
            EditAction::make(),
            Action::make('uploadCourtPhoto')->label('Добавить фото')->icon('heroicon-o-photo')
                ->schema([
                    FileUpload::make('photo')->label('Фотография зала')->image()
                        ->storeFiles(false)->required(),
                ])
                ->action(function (VenueCourt $record, array $data): void {
                    abort_unless(VenueResource::authorized(), 403);
                    $file = $data['photo'] ?? null;
                    $contents = $file?->getRealPath() ? file_get_contents($file->getRealPath()) : false;
                    if (! is_string($contents)) {
                        throw ValidationException::withMessages(['photo' => 'Не удалось прочитать изображение.']);
                    }
                    app(VenueCourtGalleryManager::class)->store($record, $contents);
                }),
            Action::make('setCourtCover')->label('Обложка')->icon('heroicon-o-star')
                ->schema([
                    Select::make('media_id')->label('Основная фотография')->required()
                        ->options(fn (VenueCourt $record): array => $this->courtPhotoOptions($record)),
                ])
                ->visible(fn (VenueCourt $record): bool => $record->media()->where('collection', 'gallery')->exists())
                ->action(function (VenueCourt $record, array $data): void {
                    abort_unless(VenueResource::authorized(), 403);
                    app(VenueCourtGalleryManager::class)->activate($record, (int) $data['media_id']);
                }),
            Action::make('removeCourtPhoto')->label('Удалить фото')->icon('heroicon-o-trash')
                ->color('danger')
                ->schema([
                    Select::make('media_id')->label('Фотография')->required()
                        ->options(fn (VenueCourt $record): array => $this->courtPhotoOptions($record)),
                ])
                ->visible(fn (VenueCourt $record): bool => $record->media()->where('collection', 'gallery')->exists())
                ->requiresConfirmation()
                ->action(function (VenueCourt $record, array $data): void {
                    abort_unless(VenueResource::authorized(), 403);
                    app(VenueCourtGalleryManager::class)->delete($record, (int) $data['media_id']);
                }),
            Action::make('editCourtSchedule')->label('Расписание')->icon('heroicon-o-clock')
                ->schema([
                    Toggle::make('override')->label('Индивидуальный график зала')
                        ->helperText('Выключено: зал автоматически наследует действующий график площадки.')
                        ->live(),
                    Repeater::make('weekly_intervals')->label('Часы работы по дням недели')
                        ->schema([
                            Select::make('day_of_week')->label('День')->required()->options([
                                1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда',
                                4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье',
                            ]),
                            TimePicker::make('starts_at')->label('С')->required()->seconds(false),
                            TimePicker::make('ends_at')->label('До')->required()->seconds(false),
                        ])->columns(3)->visible(fn (Get $get): bool => (bool) $get('override')),
                    Repeater::make('exceptions')->label('Исключения и нерабочие дни')
                        ->schema([
                            DatePicker::make('date')->label('Дата')->required(),
                            Toggle::make('is_closed')->label('Не работает')->live(),
                            TimePicker::make('starts_at')->label('С')->seconds(false)
                                ->required(fn (Get $get): bool => ! (bool) $get('is_closed')),
                            TimePicker::make('ends_at')->label('До')->seconds(false)
                                ->required(fn (Get $get): bool => ! (bool) $get('is_closed')),
                        ])->columns(4)->visible(fn (Get $get): bool => (bool) $get('override')),
                ])
                ->fillForm(fn (VenueCourt $record): array => [
                    'override' => $record->schedule !== null,
                    'weekly_intervals' => $record->schedule?->weekly_intervals ?? [],
                    'exceptions' => $record->schedule?->exceptions ?? [],
                ])
                ->action(function (VenueCourt $record, array $data): void {
                    abort_unless(VenueResource::authorized(), 403);
                    if (! ($data['override'] ?? false)) {
                        $record->schedule()->delete();
                        $record->unsetRelation('schedule');

                        return;
                    }
                    $weekly = array_values($data['weekly_intervals'] ?? []);
                    $exceptions = array_values($data['exceptions'] ?? []);
                    foreach (array_merge($weekly, $exceptions) as $row) {
                        if (! ($row['is_closed'] ?? false)
                            && (empty($row['starts_at']) || empty($row['ends_at'])
                                || $row['starts_at'] >= $row['ends_at'])) {
                            throw ValidationException::withMessages([
                                'weekly_intervals' => 'Окончание интервала должно быть позже начала.',
                            ]);
                        }
                    }
                    $record->schedule()->updateOrCreate(
                        ['venue_court_id' => $record->id],
                        ['weekly_intervals' => $weekly, 'exceptions' => $exceptions],
                    );
                    $record->unsetRelation('schedule');
                }),
            Action::make('makePrimary')->label('Сделать основной')->icon('heroicon-o-star')
                ->visible(fn (VenueCourt $record): bool => ! $record->is_primary)
                ->requiresConfirmation()
                ->action(function (VenueCourt $record): void {
                    abort_unless(VenueResource::authorized(), 403);
                    DB::transaction(function () use ($record): void {
                        $ownerId = $this->getOwnerRecord()->getKey();
                        VenueCourt::query()->where('venue_id', $ownerId)->lockForUpdate()->get();
                        $court = VenueCourt::query()->where('venue_id', $ownerId)
                            ->whereKey($record->getKey())->firstOrFail();
                        VenueCourt::query()->where('venue_id', $ownerId)->where('is_primary', true)
                            ->update(['is_primary' => false]);
                        $court->update(['is_primary' => true]);
                    });
                }),
            DeleteAction::make()->requiresConfirmation()
                ->visible(fn (VenueCourt $record): bool => ! $record->is_primary
                    && ! $this->hasFutureUsage($record))
                ->before(function (VenueCourt $record): void {
                    abort_unless(VenueResource::authorized(), 403);
                    abort_if($record->is_primary || $this->hasFutureUsage($record), 422,
                        'Основной зал или зал с будущими бронированиями/мероприятиями нельзя удалить.');
                }),
        ])->defaultSort('sort_order');
    }

    private function courtPhotoPreview(?VenueCourt $court): HtmlString
    {
        if ($court === null) {
            return new HtmlString('Сохраните зал, чтобы добавить фотографии.');
        }

        $photos = $court->media()->where('collection', 'gallery')
            ->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('id')->get();
        if ($photos->isEmpty()) {
            return new HtmlString('Фотографий ещё нет. Добавьте их действием «Добавить фото» в таблице залов.');
        }

        $markup = $photos->map(fn ($photo): string =>
            '<img src="'.e(url($photo->publicUrl())).'" alt="Фото зала" loading="lazy" '
            .'style="width:100%;max-width:150px;height:110px;object-fit:cover;border-radius:9px">'
        )->implode('');

        return new HtmlString('<div style="display:flex;gap:8px;flex-wrap:wrap">'.$markup.'</div>'
            .'<p style="margin-top:10px;font-size:12px">Добавление, выбор обложки и удаление — действия строки зала в таблице.</p>');
    }

    /** @return array<int, string> */
    private function courtPhotoOptions(VenueCourt $court): array
    {
        return $court->media()->where('collection', 'gallery')
            ->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('id')
            ->get()->mapWithKeys(fn ($photo): array => [
                $photo->id => 'Фото #'.$photo->id.($photo->is_featured ? ' (обложка)' : ''),
            ])->all();
    }

    private function hasFutureUsage(VenueCourt $court): bool
    {
        return DB::table('venue_bookings')->where('venue_court_id', $court->getKey())
            ->where('ends_at', '>', now())->exists()
            || DB::table('venue_booking_quotes')->where('venue_court_id', $court->getKey())
                ->where('starts_at', '>', now())->exists()
            || DB::table('events')->where('venue_court_id', $court->getKey())
                ->where('starts_at', '>', now())->exists();
    }
}
