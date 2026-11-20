<?php

namespace App\Filament\Backoffice\Resources\RentalInvoiceTemplates;

use App\Filament\Backoffice\Resources\RentalInvoiceTemplates\Pages\CreateRentalInvoiceTemplate;
use App\Filament\Backoffice\Resources\RentalInvoiceTemplates\Pages\EditRentalInvoiceTemplate;
use App\Filament\Backoffice\Resources\RentalInvoiceTemplates\Pages\ListRentalInvoiceTemplates;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueRentalInvoiceTemplate;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class RentalInvoiceTemplateResource extends Resource
{
    protected static ?string $model = VenueRentalInvoiceTemplate::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Аренда и документы';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';
    protected static ?int $navigationSort = 5;
    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string { return 'Шаблоны счетов'; }
    public static function getModelLabel(): string { return 'шаблон счёта'; }
    public static function getPluralModelLabel(): string { return 'шаблоны счетов'; }

    public static function canViewAny(): bool
    {
        $user = auth('web')->user();

        return $user && $user->isConfirmed()
            && $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)
            && app(\App\Modules\Identity\Application\Services\BackofficePermissionManager::class)
                ->allows($user, \App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum::BACKOFFICE_ACCESS)
            && (bool) config('features.venue_rental.invoices', false);
    }

    public static function canCreate(): bool { return self::canViewAny(); }
    public static function canEdit(Model $record): bool { return self::canViewAny(); }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Назначение шаблона')->columns(2)->schema([
                TextInput::make('name')->label('Название шаблона')
                    ->required()->maxLength(120),
                Select::make('scope')->label('Уровень')->options([
                    'global' => 'Глобальный MSKBA',
                    'venue' => 'Конкретная площадка',
                    'agent' => 'Собственный шаблон агента',
                ])->required()->live()
                    ->disabled(fn (?VenueRentalInvoiceTemplate $record): bool => $record !== null)
                    ->dehydrated(),
                Select::make('venue_id')->label('Площадка')
                    ->searchable()->preload()
                    ->options(fn (): array => Venue::query()
                        ->orderBy('name')->limit(200)->pluck('name', 'id')->all())
                    ->getSearchResultsUsing(fn (string $search): array => Venue::query()
                        ->whereLike('name', '%'.$search.'%')
                        ->orderBy('name')->limit(30)->pluck('name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Venue::query()->find($value)?->name)
                    ->visible(fn (Get $get): bool => in_array($get('scope'), ['venue', 'agent'], true))
                    ->required(fn (Get $get): bool => in_array($get('scope'), ['venue', 'agent'], true)),
                Select::make('owner_user_id')->label('Владелец агентского шаблона')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => User::query()
                        ->where('username', 'like', '%'.$search.'%')
                        ->whereNull('deleted_at')->limit(30)->pluck('username', 'id')->all())
                    ->getOptionLabelUsing(fn ($value): ?string => User::query()->find($value)?->username)
                    ->visible(fn (Get $get): bool => $get('scope') === 'agent')
                    ->required(fn (Get $get): bool => $get('scope') === 'agent'),
            ]),
            Section::make('Документ и способ оплаты')->columns(2)->schema([
                TextInput::make('title')->label('Заголовок счёта')
                    ->required()->maxLength(190)->columnSpanFull(),
                Textarea::make('description')->label('Описание')
                    ->rows(2)->maxLength(2000)->columnSpanFull(),
                CheckboxList::make('payment_methods')->label('Способы оплаты')
                    ->options([
                        'bank_transfer' => 'Банковский перевод',
                        'sbp' => 'СБП / перевод по карте',
                        'cash' => 'Наличные',
                        'other' => 'Другой способ',
                    ])->default(['bank_transfer'])->required()->columns(4)->columnSpanFull(),
                Textarea::make('payment_instructions')->label('Инструкция по оплате')
                    ->rows(3)->maxLength(4000)->columnSpanFull(),
                TextInput::make('due_minutes')->label('Окно оплаты покупателем, мин')
                    ->helperText('Срок оплаты первого счёта. Фактический дедлайн автоматически ограничивается концом холда. Сроки расчётов агента с MSKBA и MSKBA с площадкой отдельные и не продлевают бронь.')
                    ->numeric()->integer()->minValue(1)->maxValue(1440)
                    ->default(30)->required(),
                TextInput::make('priority')->label('Приоритет выбора')
                    ->numeric()->integer()->minValue(0)->maxValue(1000)
                    ->default(100)->required(),
                Toggle::make('auto_send')->label('Выставлять автоматически после холда')
                    ->helperText('Создаётся только после подтверждения внешнего удержания; не подтверждает получение денег.')
                    ->default(false),
                Toggle::make('enabled')->label('Активен')->default(true),
            ]),
        ]);
    }

    /** @param array<string,mixed> $data
     *  @return array<string,mixed>
     */
    public static function normalizedData(array $data): array
    {
        $scope = (string) ($data['scope'] ?? '');
        if (! in_array($scope, ['global', 'venue', 'agent'], true)) {
            throw new \InvalidArgumentException('Неизвестный тип шаблона.');
        }
        if ($scope !== 'global' && empty($data['venue_id'])) {
            throw new \InvalidArgumentException('Не выбрана площадка.');
        }
        if ($scope === 'agent' && empty($data['owner_user_id'])) {
            throw new \InvalidArgumentException('Не выбран агент.');
        }

        $data['venue_id'] = $scope === 'global' ? null : (int) $data['venue_id'];
        $data['owner_user_id'] = $scope === 'agent' ? (int) $data['owner_user_id'] : null;
        $data['payment_methods'] = array_values(array_unique($data['payment_methods'] ?? []));
        $data['auto_send'] = (bool) ($data['auto_send'] ?? false);
        $data['enabled'] = (bool) ($data['enabled'] ?? false);

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Название')->searchable()->sortable(),
            TextColumn::make('scope')->label('Уровень')->badge(),
            TextColumn::make('venue_id')->label('ID площадки')->placeholder('Все'),
            TextColumn::make('owner_user_id')->label('ID агента')->placeholder('—'),
            TextColumn::make('due_minutes')->label('Срок, мин'),
            IconColumn::make('auto_send')->label('Авто')->boolean(),
            IconColumn::make('enabled')->label('Активен')->boolean(),
        ])->defaultSort('id', 'desc')->filters([
            SelectFilter::make('scope')->options([
                'global' => 'Глобальный',
                'venue' => 'Площадка',
                'agent' => 'Агент',
            ]),
        ])->recordActions([EditAction::make()->label('Изменить')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRentalInvoiceTemplates::route('/'),
            'create' => CreateRentalInvoiceTemplate::route('/create'),
            'edit' => EditRentalInvoiceTemplate::route('/{record}/edit'),
        ];
    }
}
