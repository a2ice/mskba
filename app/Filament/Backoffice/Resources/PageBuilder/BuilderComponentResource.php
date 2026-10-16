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
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
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
        return $schema->columns(1)->components([
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
                        ->itemLabel(fn (array $state): ?string => ($state['name'] ?? null) ?: ($state['title'] ?? 'Новый слайд'))
                        ->reorderable()->collapsible()->cloneable()
                        ->minItems(1)->maxItems(6)->defaultItems(0)
                        ->schema([
                            Grid::make(3)->schema([
                                TextInput::make('name')->label('Название слайда (для админки)')->maxLength(90),
                                Toggle::make('enabled')->label('Слайд включён')->default(true),
                                Select::make('layout')->label('Тип слайда')->native(false)->live()
                                    ->options([
                                        'layers' => 'Универсальный · слои',
                                        'legacy' => 'MSKBA · прежний макет',
                                    ])
                                    ->default('layers')->required()
                                    ->helperText('Существующие слайды сохраняют старое оформление. Для новых выбирай «Универсальный».'),
                            ]),
                            Section::make('Кому показывать слайд')
                                ->description('Условия проверяются сервером до выдачи HTML. Неавторизованные и неподходящие пользователи не получают скрытые слайды.')
                                ->collapsible()->collapsed()
                                ->columns(2)->schema([
                                    Select::make('audience.auth')->label('Авторизация')->native(false)
                                        ->options([
                                            'all' => 'Всем', 'guest' => 'Только гостям',
                                            'authenticated' => 'Только авторизованным',
                                        ])->default('all'),
                                    Select::make('audience.confirmation')->label('Статус аккаунта')->native(false)
                                        ->options([
                                            'any' => 'Любой', 'confirmed' => 'Подтверждён',
                                            'unconfirmed' => 'Не подтверждён',
                                        ])->default('any'),
                                    CheckboxList::make('audience.system_roles')->label('Системные роли (хотя бы одна)')
                                        ->options([
                                            'user' => 'Пользователь', 'editor' => 'Редактор',
                                            'moderator' => 'Модератор', 'admin' => 'Админ',
                                            'superadmin' => 'Суперадмин',
                                        ])->columns(2)->columnSpanFull(),
                                    CheckboxList::make('audience.participation_roles')->label('Роли участия (хотя бы одна)')
                                        ->options([
                                            'player' => 'Игрок', 'coach' => 'Тренер', 'referee' => 'Судья',
                                            'statistician' => 'Статист', 'media' => 'Медиа',
                                            'venue_related' => 'Представитель площадки', 'organizer' => 'Организатор',
                                        ])->columns(2)->columnSpanFull(),
                                    Text::make('Теги пользователей пока не реализованы: интерфейс появится после внедрения общей системы тегов для персонализации страниц и рекламы.'),
                                ]),
                            Section::make('Универсальный слайд')
                                ->visible(fn (Get $get): bool => $get('layout') === 'layers')
                                ->description('Фон и произвольные элементы — текст, изображения, кнопки. Перетаскивай элементы для изменения порядка; координаты в % холста.')
                                ->schema([
                                    Grid::make(2)->schema([
                                        FileUpload::make('background_image')->label('Фон · desktop')
                                            ->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                                            ->disk('public')->directory('page-builder/hero')->visibility('public')->maxSize(4096),
                                        FileUpload::make('background_image_mobile')->label('Фон · mobile')
                                            ->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                                            ->disk('public')->directory('page-builder/hero')->visibility('public')->maxSize(3072),
                                        Select::make('scrim')->label('Затемнение/осветление фона')
                                            ->native(false)->options(['none' => 'Нет', 'dark' => 'Тёмное', 'light' => 'Светлое'])
                                            ->default('none'),
                                        Select::make('background_effect')->label('Появление фона')->native(false)
                                            ->options(self::animationOptions())->default('fade'),
                                    ]),
                                    Repeater::make('elements')->label('Элементы слайда')
                                        ->itemLabel(fn (array $state): ?string => ($state['name'] ?? null) ?: ($state['type'] ?? 'Новый элемент'))
                                        ->reorderable()->cloneable()->collapsible()
                                        ->minItems(1)->maxItems(16)->defaultItems(0)
                                        ->helperText('Обязательно добавь хотя бы один элемент слайда (заголовок, текст, изображение или кнопку) перед публикацией.')
                                        ->schema([
                                            Grid::make(3)->schema([
                                                Select::make('type')->label('Тип элемента')->native(false)->live()
                                                    ->options([
                                                        'heading' => 'Заголовок', 'text' => 'Текст',
                                                        'image' => 'Изображение', 'button' => 'Кнопка',
                                                    ])->default('text')->required(),
                                                TextInput::make('name')->label('Название элемента')->required()->maxLength(90),
                                                TextInput::make('class_name')->label('CSS-классы (до 3)')
                                                    ->maxLength(160)->regex('/\A[a-z][a-z0-9_-]{0,49}(?: [a-z][a-z0-9_-]{0,49}){0,2}\z/D')
                                                    ->helperText('Например: my-slide-title bg-lightblue. Без точек и CSS-кода.'),
                                            ]),
                                            Textarea::make('content')->label('Текст')->rows(2)->maxLength(400)
                                                ->visible(fn (Get $get): bool => in_array($get('type'), ['heading','text','button'], true))
                                                ->required(fn (Get $get): bool => in_array($get('type'), ['heading','text','button'], true)),
                                            TextInput::make('url')->label('Внутренняя ссылка кнопки')
                                                ->visible(fn (Get $get): bool => $get('type') === 'button')
                                                ->required(fn (Get $get): bool => $get('type') === 'button'),
                                            FileUpload::make('image')->label('Изображение')
                                                ->visible(fn (Get $get): bool => $get('type') === 'image')
                                                ->required(fn (Get $get): bool => $get('type') === 'image')
                                                ->image()->acceptedFileTypes(['image/jpeg','image/png','image/webp','image/avif'])
                                                ->disk('public')->directory('page-builder/hero')->visibility('public')->maxSize(4096),
                                            TextInput::make('alt')->label('Альтернативный текст изображения')
                                                ->maxLength(140)->visible(fn (Get $get): bool => $get('type') === 'image')
                                                ->helperText('Для декоративной картинки оставь пустым.'),
                                            Section::make('Положение слоя')->columns(3)->schema([
                                                TextInput::make('x')->label('Desktop X, %')->numeric()->minValue(0)->maxValue(100)->default(10),
                                                TextInput::make('y')->label('Desktop Y, %')->numeric()->minValue(0)->maxValue(100)->default(20),
                                                TextInput::make('width')->label('Desktop ширина, %')->numeric()->minValue(5)->maxValue(100)->default(50),
                                                TextInput::make('mobile_x')->label('Mobile X, %')->numeric()->minValue(0)->maxValue(100)->default(6),
                                                TextInput::make('mobile_y')->label('Mobile Y, %')->numeric()->minValue(0)->maxValue(100)->default(20),
                                                TextInput::make('mobile_width')->label('Mobile ширина, %')->numeric()->minValue(5)->maxValue(100)->default(88),
                                                TextInput::make('z_index')->label('Слой Z (2–7)')->numeric()->minValue(2)->maxValue(7)->default(4),
                                                Select::make('align')->label('Выравнивание')->native(false)->options([
                                                    'left' => 'Слева', 'center' => 'По центру', 'right' => 'Справа',
                                                ])->default('left'),
                                            ])->collapsible()->collapsed(),
                                            Section::make('Таймлайн и эффект')->columns(3)->schema([
                                                Select::make('animation')->label('Эффект')->native(false)
                                                    ->options(self::animationOptions())->default('fade'),
                                                TextInput::make('delay_ms')->label('Задержка, мс')
                                                    ->numeric()->minValue(0)->maxValue(5000)->default(0),
                                                TextInput::make('duration_ms')->label('Продолжительность, мс')
                                                    ->numeric()->minValue(100)->maxValue(3000)->default(700),
                                            ])->collapsible()->collapsed(),
                                        ]),
                                ]),
                            Section::make('Старый макет MSKBA')
                                ->description('Совместимый режим текущей главной. Не влияет на универсальные слайды.')
                                ->collapsible()->collapsed()
                                ->visible(fn (Get $get): bool => ($get('layout') ?? 'legacy') === 'legacy')
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
                                Select::make('animation_background')->label('Появление фона')
                                    ->options(self::animationOptions())->default('fade')->native(false),
                                Select::make('animation_highlights')->label('Появление хайлайтов')
                                    ->options(self::animationOptions())->default('fade')->native(false),
                                Select::make('animation_cta')->label('Появление кнопки')
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
                            Section::make('Таймлайн · задержки анимаций, мс')
                                ->description('0–5000 мс. При наведении и фокусе автопереключение приостанавливается.')
                                ->collapsible()->collapsed()->columns(3)
                                ->schema([
                                    TextInput::make('delay_background_ms')->label('Фон')
                                        ->numeric()->minValue(0)->maxValue(5000)->default(0),
                                    TextInput::make('delay_far_ms')->label('Дальний игрок')
                                        ->numeric()->minValue(0)->maxValue(5000)->default(240),
                                    TextInput::make('delay_near_ms')->label('Ближний игрок')
                                        ->numeric()->minValue(0)->maxValue(5000)->default(460),
                                    TextInput::make('delay_copy_ms')->label('Заголовок и текст')
                                        ->numeric()->minValue(0)->maxValue(5000)->default(440),
                                    TextInput::make('delay_highlights_ms')->label('Хайлайты')
                                        ->numeric()->minValue(0)->maxValue(5000)->default(1050),
                                    TextInput::make('delay_cta_ms')->label('Кнопка')
                                        ->numeric()->minValue(0)->maxValue(5000)->default(1450),
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
                ]),
            Section::make('HTML и CSS')->columns(1)
                ->hidden(fn (?PageBuilderComponent $record): bool => $record?->slug === 'hero-slider')
                ->schema([
                    CodeEditor::make('draft_html')->label('HTML')->language(Language::Html)
                        ->required(fn (?PageBuilderComponent $record): bool => $record?->slug !== 'hero-slider')
                        ->columnSpanFull(),
                    CodeEditor::make('draft_css')->label('CSS')->language(Language::Css)
                        ->columnSpanFull(),
                ])->columnSpanFull(),
            BuilderPlaceholderGuide::panel()->collapsible()
                ->hidden(fn (?PageBuilderComponent $record): bool => $record?->slug === 'hero-slider')
                ->columnSpanFull(),
        ]);
    }

    private static function animationOptions(): array
    {
        return [
            'none' => 'Без анимации',
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
