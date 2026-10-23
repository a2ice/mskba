<?php

namespace App\Filament\Backoffice\Resources\DesignThemes;

use App\Filament\Backoffice\Resources\DesignThemes\Pages\CreateDesignTheme;
use App\Filament\Backoffice\Resources\DesignThemes\Pages\EditDesignTheme;
use App\Filament\Backoffice\Resources\DesignThemes\Pages\ListDesignThemes;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Presentation\Theming\DesignTheme;
use App\Presentation\Theming\DesignThemeCss;
use App\Presentation\Theming\DesignFontCatalog;
use App\Presentation\Theming\DesignThemeManager;
use App\Presentation\Theming\DesignThemeRevision;
use App\Presentation\Theming\DesignThemeRevisionManager;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class DesignThemeResource extends Resource
{
    protected static ?string $model = DesignTheme::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-paint-brush';

    protected static string|\UnitEnum|null $navigationGroup = 'Сайт';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return 'Темы оформления';
    }

    public static function getModelLabel(): string
    {
        return 'тема оформления';
    }

    public static function getPluralModelLabel(): string
    {
        return 'темы оформления';
    }

    private static function allowed(Permission $permission): bool
    {
        $user = auth('web')->user();

        return $user && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_ACCESS)
            && app(BackofficePermissionManager::class)->allows($user, $permission);
    }

    public static function canViewAny(): bool
    {
        return self::allowed(Permission::BACKOFFICE_THEME_VIEW);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof DesignTheme && self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return self::allowed(Permission::BACKOFFICE_THEME_MANAGE);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof DesignTheme && self::canCreate();
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof DesignTheme && self::canCreate()
            && ! $record->is_system
            && $record->getKey() !== app(DesignThemeManager::class)->default()->getKey();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $colors = [];
        foreach (DesignThemeCss::COLORS as $key => $label) {
            $colors[] = ColorPicker::make('colors.'.$key)->label($label)->required()
                ->default(config('design_palettes.night.colors.'.$key));
        }

        return $schema->components([
            Section::make('Тема оформления')
                ->description('Визуальные темы не меняют Blade/Vue-страницы. Две системные темы удалить нельзя.')
                ->columns(2)->schema([
                    TextInput::make('name')->label('Название')->required()->maxLength(100),
                    TextInput::make('slug')->label('Код темы')->required()->maxLength(60)
                        ->regex('/\A[a-z][a-z0-9_]{2,59}\z/D')
                        ->unique(ignoreRecord: true)->disabled(fn (?DesignTheme $record) => $record !== null)
                        ->dehydrated(fn (?DesignTheme $record) => $record === null),
                    Select::make('mode')->label('Цветовая схема')
                        ->options(['dark' => 'Тёмная', 'light' => 'Светлая'])->required()
                        ->default('dark')->disabled(fn (?DesignTheme $record) => $record?->is_system ?? false)
                        ->dehydrated(fn (?DesignTheme $record) => ! ($record?->is_system ?? false)),
                    Toggle::make('is_published')->label('Доступна пользователям')
                        ->default(true)->disabled(fn (?DesignTheme $record) => $record?->is_system ?? false)
                        ->dehydrated(fn (?DesignTheme $record) => ! ($record?->is_system ?? false)),
                    Textarea::make('description')->label('Описание')->maxLength(255)->columnSpanFull(),
                ]),
            Section::make('Типографика')
                ->description('Шрифты из лицензированной локальной коллекции. Каждый можно предварительно сравнить в карточке темы.')
                ->columns(2)->schema([
                    Select::make('body_font')->label('Шрифт основного текста')
                        ->options(DesignFontCatalog::OPTIONS)->native(false)->required()->default('inter'),
                    Select::make('heading_font')->label('Шрифт заголовков H1–H3')
                        ->options(DesignFontCatalog::OPTIONS)->native(false)->required()->default('inter'),
                ]),
            Section::make('Боковая подсветка карточек')
                ->description('Цвет темы используется по умолчанию. Цвет, установленный у отдельного виджета, имеет приоритет.')
                ->columns(2)->schema([
                    ColorPicker::make('widget_glow_color')->label('Цвет свечения по умолчанию')
                        ->nullable()->regex('/\\A#[0-9a-fA-F]{6}\\z/D'),
                    Select::make('widget_glow_strength')->label('Интенсивность')
                        ->options([0 => 'Выключено', 8 => 'Очень слабая — 8%', 12 => 'Деликатная — 12%', 16 => 'Слабая — 16%', 20 => 'Стандартная — 20%', 28 => 'Выразительная — 28%', 36 => 'Сильная — 36%'])
                        ->native(false)->required()->default(20),
                ]),
            Section::make('Палитра бокового меню')
                ->description('Отдельные настройки сайдбара, не изменяющие фон карточек и кнопок. Действуют в обеих темах.')
                ->columns(3)->schema([
                    ColorPicker::make('sidebar_background')->label('Фон панели')
                        ->nullable()->regex('/\\A#[0-9a-fA-F]{6}\\z/D'),
                    ColorPicker::make('sidebar_text')->label('Обычные пункты')
                        ->nullable()->regex('/\\A#[0-9a-fA-F]{6}\\z/D'),
                    ColorPicker::make('sidebar_hover')->label('Фон при наведении')
                        ->nullable()->regex('/\\A#[0-9a-fA-F]{6}\\z/D'),
                    ColorPicker::make('sidebar_active_background')->label('Фон активного пункта')
                        ->nullable()->regex('/\\A#[0-9a-fA-F]{6}\\z/D'),
                    ColorPicker::make('sidebar_active_text')->label('Текст активного пункта')
                        ->nullable()->regex('/\\A#[0-9a-fA-F]{6}\\z/D'),
                ]),
            Section::make('Палитра')
                ->description('Применяется к фону, тексту, формам, кнопкам, сообщениям и статусным компонентам.')
                ->columns(3)->schema($colors),
            Section::make('Расширенные CSS-токены')
                ->description('Только переменные в блоке :root. Для глобальных CSS-классов и JavaScript используй кнопки CSS / JS в шапке этой страницы. Токены сохраняются отдельно от кода темы.')
                ->schema([
                    CodeEditor::make('css_code')->label('CSS переменных')->language(Language::Css)
                        ->default(config('design_palettes.night.css_code'))->columnSpanFull(),
                ]),
        ]);
    }

    public static function validateTheme(array $data): void
    {
        abort_unless(self::canCreate(), 403);
        app(DesignThemeCss::class)->validate((array) ($data['colors'] ?? []), $data['css_code'] ?? null);
        foreach (['body_font', 'heading_font'] as $field) {
            if (! isset(DesignFontCatalog::OPTIONS[$data[$field] ?? 'inter'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    $field => 'Выбери шрифт из коллекции MSKBA.',
                ]);
            }
        }
        $glow = $data['widget_glow_color'] ?? null;
        if ($glow !== null && $glow !== '' && (! is_string($glow) || ! preg_match('/\\A#[0-9a-fA-F]{6}\\z/D', $glow))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'widget_glow_color' => 'Выбери цвет в формате HEX.',
            ]);
        }
        foreach (['sidebar_background', 'sidebar_text', 'sidebar_hover', 'sidebar_active_background', 'sidebar_active_text'] as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null && $value !== ''
                && (! is_string($value) || ! preg_match('/\\A#[0-9a-fA-F]{6}\\z/D', $value))) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    $field => 'Выбери цвет в формате HEX.',
                ]);
            }
        }
        if (isset($data['widget_glow_strength']) && ! in_array((int) $data['widget_glow_strength'], [0, 8, 12, 16, 20, 28, 36], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'widget_glow_strength' => 'Выбери допустимую интенсивность подсветки.',
            ]);
        }
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Тема')->weight('bold')->searchable()
                ->url(fn (DesignTheme $record): string => self::canEdit($record)
                    ? self::getUrl('edit', ['record' => $record])
                    : route('design-themes.ui-kit', $record)),
            TextColumn::make('slug')->label('Код')->copyable(),
            TextColumn::make('mode')->label('Режим')->formatStateUsing(fn (string $state) => $state === 'light' ? 'Светлая' : 'Тёмная'),
            IconColumn::make('is_system')->label('Системная')->boolean(),
            IconColumn::make('is_published')->label('Опубликована')->boolean(),
            TextColumn::make('global')
                ->label('По умолчанию')
                ->state(fn (DesignTheme $record): string => $record->getKey() === app(DesignThemeManager::class)->default()->getKey() ? 'Да' : '—'),
        ])->recordActions([
            Action::make('duplicate')->label('Дублировать')->icon('heroicon-o-document-duplicate')
                ->visible(fn (): bool => self::canCreate())
                ->requiresConfirmation()
                ->action(function (DesignTheme $record, $livewire): void {
                    $copy = $record->replicate(['slug', 'is_system', 'is_published']);
                    $copy->name = mb_substr($record->name.' (копия)', 0, 100);
                    $copy->slug = substr($record->slug, 0, 40).'_'.bin2hex(random_bytes(4));
                    $copy->is_system = false;
                    $copy->is_published = false;
                    $copy->save();
                    $livewire->redirect(self::getUrl('edit', ['record' => $copy]));
                }),
            Action::make('history')->label('История версий')->icon('heroicon-o-clock')
                ->modalHeading('История версий и восстановление')
                ->modalDescription('Каждое сохранение создаёт новую версию. Выбор предыдущей версии восстановит её параметры и сохранит точку возврата.')
                ->modalSubmitActionLabel('Восстановить тему')
                ->visible(fn (): bool => self::canCreate())
                ->form([
                    Select::make('revision_id')->label('Восстановить версию')
                        ->options(fn (DesignTheme $record): array => $record->revisions()
                            ->orderByDesc('id')->limit(50)->get()
                            ->mapWithKeys(fn (DesignThemeRevision $version): array => [
                                $version->id => '#'.$version->id.' · '
                                    .$version->created_at->format('d.m.Y H:i:s')
                                    .' · '.$version->name,
                            ])->all())
                        ->required()->searchable(),
                ])
                ->requiresConfirmation()
                ->action(fn (array $data, DesignTheme $record) => app(DesignThemeRevisionManager::class)
                    ->restore($record, (int) $data['revision_id'])),
            Action::make('preview')->label('Предпросмотр')->icon('heroicon-o-eye')
                ->url(fn (DesignTheme $record) => route('design-themes.preview', $record))
                ->openUrlInNewTab(),
            Action::make('uiKit')->label('UI Kit / Гайдлайн')->icon('heroicon-o-swatch')
                ->url(fn (DesignTheme $record): string => route('design-themes.ui-kit', $record))
                ->openUrlInNewTab(),
            Action::make('default')->label('Для всех')->icon('heroicon-o-check-circle')
                ->visible(fn (DesignTheme $record): bool => self::canCreate()
                    && $record->is_published
                    && $record->getKey() !== app(DesignThemeManager::class)->default()->getKey())
                ->requiresConfirmation()
                ->action(fn (DesignTheme $record) => app(DesignThemeManager::class)->selectDefault($record)),
            EditAction::make(),
            DeleteAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDesignThemes::route('/'),
            'create' => CreateDesignTheme::route('/create'),
            'edit' => EditDesignTheme::route('/{record}/edit'),
        ];
    }
}
