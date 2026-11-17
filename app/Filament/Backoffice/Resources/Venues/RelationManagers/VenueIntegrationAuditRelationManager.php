<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\VenueIntegration\Application\Services\VenueIntegrationManager;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class VenueIntegrationAuditRelationManager extends RelationManager
{
    protected static string $relationship = 'integrationAuditEvents';
    protected static ?string $title = 'Интеграции: журнал изменений';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $actor = auth('web')->user();

        return VenueResource::authorized() && ! $ownerRecord->trashed()
            && $actor !== null
            && app(VenueIntegrationManager::class)->can(
                $actor, Permission::BACKOFFICE_VENUE_INTEGRATIONS_VIEW,
            )
            && app(VenueIntegrationManager::class)->can(
                $actor, Permission::BACKOFFICE_VENUE_INTEGRATIONS_AUDIT,
            );
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Время')->dateTime('d.m.Y H:i:s')->sortable(),
                TextColumn::make('actor_user_id')->label('Пользователь'),
                TextColumn::make('action')->label('Действие'),
                TextColumn::make('venue_integration_source_id')->label('Источник')->placeholder('Конфигурация'),
                TextColumn::make('changes')->label('Изменения (без секретов)')
                    ->formatStateUsing(fn ($state): string =>
                        json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—')
                    ->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Изменений интеграции пока нет');
        // Deliberately no create/edit/delete actions. The backend audit is append-only.
    }
}
