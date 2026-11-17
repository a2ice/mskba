<?php

namespace App\Filament\Backoffice\Resources\DashboardButtonStyles;

use App\Filament\Backoffice\Resources\DashboardButtonStyles\Pages\CreateDashboardButtonStyle;
use App\Filament\Backoffice\Resources\DashboardButtonStyles\Pages\EditDashboardButtonStyle;
use App\Filament\Backoffice\Resources\DashboardButtonStyles\Pages\ListDashboardButtonStyles;
use App\Modules\Dashboard\Application\Services\DashboardButtonStyleCompiler;
use App\Modules\Dashboard\Domain\Models\DashboardButtonStyle;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class DashboardButtonStyleResource extends Resource
{
    protected static ?string $model = DashboardButtonStyle::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

    protected static ?int $navigationSort = 2;

    protected static string|\UnitEnum|null $navigationGroup = 'Сайт';

    public static function getNavigationLabel(): string
    {
        return 'Стили кнопок';
    }

    public static function getModelLabel(): string
    {
        return 'стиль кнопки';
    }

    public static function getPluralModelLabel(): string
    {
        return 'стили кнопок';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Переиспользуемый стиль')
                ->description('CSS применяется только к кнопкам виджетов личного кабинета. Другие элементы портала не меняются.')
                ->columns(2)->schema([
                    TextInput::make('name')->label('Название для редактора')
                        ->required()->maxLength(100)->placeholder('Зелёная кнопка'),
                    TextInput::make('class_name')->label('Имя класса без точки')
                        ->required()->maxLength(40)->regex('/\A[a-z][a-z0-9_-]{1,39}\z/D')
                        ->unique(ignoreRecord: true)->placeholder('bg-green')
                        ->helperText('Например: bg-green. Применяется в разделе «Действия» виджета.'),
                    CodeEditor::make('css_code')->label('CSS-правило')->language(Language::Css)
                        ->required()->columnSpanFull()
                        ->default('.bg-green { background-color: green !important; }')
                        ->helperText('Допускаются несколько правил одного класса: .bg-green, .bg-green:hover, .bg-green::before, .bg-green:hover::after. Для декоративных псевдоэлементов доступны content: "", position: absolute, размеры и координаты. Можно использовать CSS-комментарии /* ... */ внутри правил. Глобальные селекторы, url() и @import запрещены.'),
                    Toggle::make('enabled')->label('Доступен для кнопок')->default(true),
                ]),
        ]);
    }

    public static function validateStyle(array &$data): void
    {
        $user = auth('web')->user();
        $checker = app(BackofficePermissionManager::class);
        abort_unless($user
            && $checker->allows($user, Permission::BACKOFFICE_ACCESS)
            && $checker->allows($user, Permission::BACKOFFICE_STYLE_VIEW)
            && $checker->allows($user, Permission::BACKOFFICE_STYLE_MANAGE), 403);

        app(DashboardButtonStyleCompiler::class)->parse(
            (string) ($data['class_name'] ?? ''), (string) ($data['css_code'] ?? ''),
        );
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Стиль')->searchable()->weight('bold'),
            TextColumn::make('class_name')->label('CSS-класс')->copyable()->searchable(),
            IconColumn::make('enabled')->label('Включён')->boolean(),
            TextColumn::make('updated_at')->label('Обновлён')->dateTime('d.m.Y H:i')->sortable(),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDashboardButtonStyles::route('/'),
            'create' => CreateDashboardButtonStyle::route('/create'),
            'edit' => EditDashboardButtonStyle::route('/{record}/edit'),
        ];
    }
}
