<?php

namespace App\Filament\Backoffice\Resources\PageBuilder;

use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderPermissions;
use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderPlaceholderGuide;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\CreateBuilderComponent;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\EditBuilderComponent;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\ListBuilderComponents;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class BuilderComponentResource extends Resource
{
    use BuilderPermissions;

    protected static ?string $model = PageBuilderComponent::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Дизайн';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';
    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string { return 'Компоненты'; }
    public static function getModelLabel(): string { return 'компонент'; }
    public static function getPluralModelLabel(): string { return 'компоненты'; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Компонент')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(150),
                TextInput::make('slug')->label('Плейсхолдер')->required()
                    ->regex('/\A[a-z][a-z0-9_-]{1,79}\z/D')->unique(ignoreRecord: true)
                    ->disabled(fn (?PageBuilderComponent $record): bool => $record?->slug === 'hero-slider')
                    ->helperText('Пример: hero-banner. Для системных источников используй games-list.'),
                Textarea::make('description')->label('Краткое описание')->maxLength(500)->columnSpanFull(),
            ]),
            Section::make('Hero Slider · поведение')
                ->description('Настройки относятся только к системному компоненту hero-slider. Существующая главная не изменится до отдельной публикации страницы.')
                ->visible(fn (?PageBuilderComponent $record): bool => $record?->slug === 'hero-slider')
                ->columns(4)
                ->schema([
                    Toggle::make('draft_options.autoplay')->label('Автоматическое переключение')->default(false),
                    TextInput::make('draft_options.interval_ms')->label('Интервал, мс')
                        ->numeric()->minValue(4000)->maxValue(30000)->default(7000),
                    Toggle::make('draft_options.show_arrows')->label('Стрелки')->default(true),
                    Toggle::make('draft_options.show_dots')->label('Индикаторы')->default(true),
                ]),
            Section::make('Слайды · порядок и содержимое')
                ->description('Фон арены — единое изображение. Два игрока — отдельные прозрачные PNG/WebP. На mobile хайлайты скрыты, нижняя навигация сайта не входит в этот компонент.')
                ->visible(fn (?PageBuilderComponent $record): bool => $record?->slug === 'hero-slider')
                ->schema([
                    Repeater::make('draft_slides')->label('Слайды')
                        ->itemLabel(fn (array $state): ?string => $state['title'] ?? 'Новый слайд')
                        ->reorderable()->collapsible()->cloneable()
                        ->minItems(1)->maxItems(6)
                        ->schema([
                            Grid::make(2)->schema([
                                TextInput::make('eyebrow')->label('Надпись над заголовком')->maxLength(90),
                                Textarea::make('title')->label('Заголовок (переносы разрешены)')
                                    ->rows(2)->required()->maxLength(130),
                                TextInput::make('accent_title')->label('Оранжевая строка заголовка')->maxLength(90),
                                Textarea::make('description')->label('Описание')->rows(2)->maxLength(360),
                                TextInput::make('cta_label')->label('Текст кнопки')->required()->maxLength(45),
                                TextInput::make('cta_url')->label('Ссылка кнопки')
                                    ->required()->helperText('Внутренний URL /about или якорь #about.'),
                            ]),
                            Grid::make(2)->schema([
                                FileUpload::make('background_desktop')->label('Фон арены · desktop/tablet')
                                    ->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                                    ->disk('public')->directory('page-builder/hero')->visibility('public')->maxSize(4096),
                                FileUpload::make('background_mobile')->label('Альтернативный фон · mobile')
                                    ->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                                    ->disk('public')->directory('page-builder/hero')->visibility('public')->maxSize(3072),
                                FileUpload::make('far_player')->label('Дальний игрок · прозрачный PNG/WebP')
                                    ->image()->acceptedFileTypes(['image/png', 'image/webp'])
                                    ->disk('public')->directory('page-builder/hero')->visibility('public')->maxSize(2048),
                                FileUpload::make('near_player')->label('Ближний игрок с мячом · прозрачный PNG/WebP')
                                    ->image()->acceptedFileTypes(['image/png', 'image/webp'])
                                    ->disk('public')->directory('page-builder/hero')->visibility('public')->maxSize(2048),
                            ]),
                            Grid::make(3)->schema([
                                Select::make('animation_far')->label('Появление дальнего игрока')
                                    ->options(self::animationOptions())->default('slide-up')->native(false),
                                Select::make('animation_near')->label('Появление ближнего игрока')
                                    ->options(self::animationOptions())->default('zoom')->native(false),
                                Select::make('animation_copy')->label('Появление текста')
                                    ->options(self::animationOptions())->default('slide-up')->native(false),
                                TextInput::make('far_x')->label('Дальний игрок X · desktop, %')
                                    ->numeric()->minValue(0)->maxValue(100)->default(45),
                                TextInput::make('near_x')->label('Ближний игрок X · desktop, %')
                                    ->numeric()->minValue(0)->maxValue(100)->default(72),
                                TextInput::make('far_width')->label('Ширина дальнего игрока, %')
                                    ->numeric()->minValue(5)->maxValue(65)->default(18),
                                TextInput::make('near_width')->label('Ширина ближнего игрока, %')
                                    ->numeric()->minValue(5)->maxValue(90)->default(34),
                                TextInput::make('far_mobile_x')->label('Дальний игрок X · mobile, %')
                                    ->numeric()->minValue(0)->maxValue(100)->default(22),
                                TextInput::make('near_mobile_x')->label('Ближний игрок X · mobile, %')
                                    ->numeric()->minValue(0)->maxValue(100)->default(75),
                            ]),
                            Repeater::make('highlights')->label('Хайлайты (скрыты на mobile)')
                                ->collapsible()->reorderable()->maxItems(5)
                                ->schema([
                                    Select::make('icon')->label('Иконка')->required()->native(false)
                                        ->options(['court' => 'Площадка', 'trophy' => 'Кубок',
                                            'users' => 'Команда', 'video' => 'Медиа', 'chart' => 'Рейтинг']),
                                    TextInput::make('label')->label('Подпись')->maxLength(35)->required(),
                                    TextInput::make('url')->label('Внутренняя ссылка')->required(),
                                ])->columns(3),
                        ]),
                ]),
            Grid::make(3)->schema([
                Section::make('HTML и CSS')->columnSpan(2)->schema([
                    CodeEditor::make('draft_html')->label('HTML')->language(Language::Html)
                        ->required(fn (?PageBuilderComponent $record): bool => $record?->slug !== 'hero-slider')
                        ->hidden(fn (?PageBuilderComponent $record): bool => $record?->slug === 'hero-slider'),
                    CodeEditor::make('draft_css')->label('CSS')->language(Language::Css)
                        ->hidden(fn (?PageBuilderComponent $record): bool => $record?->slug === 'hero-slider'),
                ]),
                BuilderPlaceholderGuide::panel()->columnSpan(1),
            ]),
        ]);
    }

    private static function animationOptions(): array
    {
        return [
            'fade' => 'Fade-in',
            'slide-up' => 'Slide-up',
            'slide-left' => 'Slide-left',
            'zoom' => 'Zoom-in',
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Компонент')->searchable(),
            TextColumn::make('slug')->label('Плейсхолдер')->searchable(),
            TextColumn::make('published_at')->label('Опубликован')->dateTime('d.m.Y H:i')->placeholder('Черновик'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBuilderComponents::route('/'),
            'create' => CreateBuilderComponent::route('/create'),
            'edit' => EditBuilderComponent::route('/{record}/edit'),
        ];
    }
}
