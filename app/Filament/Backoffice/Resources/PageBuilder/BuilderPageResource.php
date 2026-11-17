<?php

namespace App\Filament\Backoffice\Resources\PageBuilder;

use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderPermissions;
use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderPlaceholderGuide;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\CreateBuilderPage;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\EditBuilderPage;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\ListBuilderPages;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Presentation\Theming\DesignTheme;
use App\Modules\Content\Application\Services\PageBuilderRouteRegistry;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

final class BuilderPageResource extends Resource
{
    use BuilderPermissions;

    protected static ?string $model = PageBuilderPage::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Сайт';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';
    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string { return 'Страницы'; }
    public static function getModelLabel(): string { return 'страница'; }
    public static function getPluralModelLabel(): string { return 'страницы'; }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Страница')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(150),
                TextInput::make('path')->label('URL')->required()->live(onBlur: true)
                    ->rule(fn (Get $get, ?PageBuilderPage $record) => Rule::unique('page_builder_pages', 'path')
                        ->where('design_theme_id', $get('design_theme_id'))->ignore($record?->getKey()))
                    ->regex('~\A/(?:[a-z0-9]+(?:-[a-z0-9]+)*)?\z~D')
                    ->helperText('Главная: / · Новая: /about · Существующий публичный маршрут: /events. Системные маршруты защищены.'),
                Select::make('design_theme_id')->label('Область отображения')
                    ->options(fn (): array => DesignTheme::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->native(false)->searchable()->live()->placeholder('Для всех')
                    ->helperText('Для всех — базовая страница. Конкретная тема переопределяет базовую только при её выборе.'),
                Select::make('template_id')->label('Шаблон')
                    ->relationship('template', 'name')->searchable()->preload()->native(false),
            ]),
            Section::make('Маршрутизация')
                ->description(fn (Get $get): string => app(PageBuilderRouteRegistry::class)
                    ->description((string) ($get('path') ?? '')))
                ->collapsible()
                ->schema([
                    Text::make('Тип определяется зарегистрированными маршрутами: обычная CMS-страница или разрешённый публичный Laravel-маршрут.'),
                    Text::make('Для детальных страниц сущностей и форм предусмотрен отдельный следующий этап.'),
                    Text::make('При первом опубликовании существующего маршрута Filament запросит подтверждение. Возврат к Laravel доступен без деплоя.'),
                ]),
            Tabs::make('Редактор')->tabs([
                Tab::make('HTML')->schema([
                    Section::make('Код')->columns(1)->schema([
                        CodeEditor::make('draft_html')->label('HTML')->language(Language::Html)
                            ->required()->columnSpanFull(),
                    ])->columnSpanFull(),
                    BuilderPlaceholderGuide::panel()->collapsible()->columnSpanFull(),
                ]),
                Tab::make('SEO')->schema([
                    TextInput::make('meta_title')->label('Meta title')->maxLength(180),
                    TextInput::make('meta_description')->label('Meta description')->maxLength(500),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Страница')->searchable(),
            TextColumn::make('path')->label('URL')->searchable()->copyable(),
            TextColumn::make('designTheme.name')->label('Область отображения')->placeholder('Для всех'),
            TextColumn::make('published_route_name')->label('Маршрут Laravel')->placeholder('Обычная CMS-страница'),
            TextColumn::make('published_at')->label('Опубликовано')->dateTime('d.m.Y H:i')->placeholder('Черновик'),
            TextColumn::make('updated_at')->label('Изменено')->dateTime('d.m.Y H:i'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBuilderPages::route('/'),
            'create' => CreateBuilderPage::route('/create'),
            'edit' => EditBuilderPage::route('/{record}/edit'),
        ];
    }
}
