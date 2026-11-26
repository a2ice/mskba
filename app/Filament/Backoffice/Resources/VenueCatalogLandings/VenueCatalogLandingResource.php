<?php

namespace App\Filament\Backoffice\Resources\VenueCatalogLandings;

use App\Filament\Backoffice\Resources\VenueCatalogLandings\Pages\CreateVenueCatalogLanding;
use App\Filament\Backoffice\Resources\VenueCatalogLandings\Pages\EditVenueCatalogLanding;
use App\Filament\Backoffice\Resources\VenueCatalogLandings\Pages\ListVenueCatalogLandings;
use App\Modules\Content\Domain\Models\VenueCatalogLanding;
use App\Modules\Content\Application\Services\VenueCatalogLandingNavigation;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\District;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

final class VenueCatalogLandingResource extends Resource
{
    protected static ?string $model = VenueCatalogLanding::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';
    protected static ?int $navigationSort = 3;
    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string { return 'SEO-посадочные страницы'; }
    public static function getModelLabel(): string { return 'SEO-посадочная'; }
    public static function getPluralModelLabel(): string { return 'SEO-посадочные страницы'; }
    public static function canViewAny(): bool { return Gate::allows('manage-content'); }
    public static function canView(Model $record): bool { return $record instanceof VenueCatalogLanding && self::canViewAny(); }
    public static function canCreate(): bool { return self::canViewAny(); }
    public static function canEdit(Model $record): bool { return self::canView($record); }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Посадочная страница')->columns(2)->schema([
                TextInput::make('name')->label('Внутреннее название')->required()->maxLength(255),
                TextInput::make('slug')->label('ЧПУ')->required()->maxLength(180)
                    ->regex('~\A'.VenueCatalogLandingNavigation::SLUG_PATTERN.'\z~D')
                    ->unique(ignoreRecord: true)->helperText('Например rent/moscow → /venues/collections/rent/moscow. Активная подборка доступна по этому адресу.'),
                TextInput::make('h1')->label('H1')->required()->maxLength(255)->columnSpanFull(),
                Toggle::make('is_active')->label('Активна')->default(false)
                    ->helperText('Активная подборка доступна по ЧПУ; черновик — только в предпросмотре.'),
            ]),
            Section::make('Условия выборки')->columns(2)->schema([
                Select::make('city_id')->label('Город')->options(fn () => City::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->live()->afterStateUpdated(fn (Set $set) => $set('district_id', null)),
                Select::make('district_id')->label('Округ или район')
                    ->options(fn (Get $get) => filled($get('city_id')) ? District::query()->where('city_id', $get('city_id'))->orderBy('name')->pluck('name', 'id')->all() : [])
                    ->searchable()->disabled(fn (Get $get) => blank($get('city_id')))->dehydrated(),
                Select::make('type')->label('Типы площадок')->multiple()->default([])->placeholder('Все типы')->helperText('Если ничего не выбрано, ограничение по типу не применяется.')->options(collect(VenueTypeEnum::cases())->mapWithKeys(fn ($type) => [$type->value => $type->label()])->all()),
                Select::make('operational_status')->label('Состояние')->options(collect(VenueOperationalStatusEnum::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all()),
                Select::make('access')->label('Условия доступа')->options(['free' => 'Бесплатно', 'paid' => 'Платно', 'approval' => 'По подтверждению']),
                Toggle::make('rental_configured')->label('Аренда настроена')->default(false),
            ]),
            Section::make('Контент')->schema([
                CodeEditor::make('intro_text')->label('Текст над каталогом')->language(Language::Html),
                CodeEditor::make('body_text')->label('Текст под каталогом')->language(Language::Html),
                Select::make('related_landing_ids')->label('Связанные подборки площадок')
                    ->multiple()->searchable()->maxItems(12)->default([])
                    ->options(fn (?VenueCatalogLanding $record) => VenueCatalogLanding::query()
                        ->when($record !== null, fn ($query) => $query->where('id', '!=', $record->getKey()))
                        ->orderBy('name')->get(['id', 'name', 'is_active'])
                        ->mapWithKeys(fn ($landing) => [$landing->id => $landing->name.($landing->is_active ? '' : ' (черновик)')])->all())
                    ->helperText('Ссылки на выбранные активные подборки появятся под каталогом. Черновики можно выбрать заранее: ссылки появятся после активации. Обратную ссылку настройте в другой подборке.'),
            ]),
            Section::make('SEO')->columns(2)->schema([
                TextInput::make('meta_title')->label('Meta Title')->maxLength(255),
                Textarea::make('meta_description')->label('Meta Description')->maxLength(320),
                Toggle::make('allow_indexing')->label('Разрешить индексацию на production')->default(false),
                Toggle::make('include_in_sitemap')->label('Включить в sitemap на production')->default(false),
            ])->description('Настройки не включают индексацию dev. Внешняя публикация пока не подключена.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('updated_at', 'desc')->columns([
            TextColumn::make('name')->label('Название')->searchable(),
            TextColumn::make('slug')->label('ЧПУ')->searchable()->copyable(),
            TextColumn::make('is_active')->label('Статус')->formatStateUsing(fn ($state) => $state ? 'Активна' : 'Черновик'),
            TextColumn::make('updated_at')->label('Изменено')->dateTime('d.m.Y H:i')->sortable(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVenueCatalogLandings::route('/'),
            'create' => CreateVenueCatalogLanding::route('/create'),
            'edit' => EditVenueCatalogLanding::route('/{record}/edit'),
        ];
    }
}
