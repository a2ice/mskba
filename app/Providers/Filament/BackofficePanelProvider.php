<?php

namespace App\Providers\Filament;

use App\Filament\Backoffice\Pages\Commissions;
use App\Filament\Backoffice\Pages\GraphQLExplorer;
use App\Filament\Backoffice\Pages\PageBuilderAgentAccess;
use App\Filament\Backoffice\Pages\EditorialNewsAgentAccess;
use App\Filament\Backoffice\Pages\SocialIntegrationSettingsPage;
use App\Filament\Backoffice\Pages\UiStudioPage;
use App\Filament\Backoffice\Pages\EnvironmentStatePage;
use App\Filament\Backoffice\Pages\ProjectUpdatesAgentAccess;
use App\Filament\Backoffice\Pages\RestApiExplorer;
use App\Filament\Backoffice\Resources\AccountDashboardWidgets\AccountDashboardWidgetResource;
use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use App\Filament\Backoffice\Resources\AccountInvitations\AccountInvitationResource;
use App\Filament\Backoffice\Resources\VenueOwnershipClaims\VenueOwnershipClaimResource;
use App\Filament\Backoffice\Resources\Contracts\ContractResource;
use App\Filament\Backoffice\Resources\ContractTemplates\ContractTemplateResource;
use App\Filament\Backoffice\Resources\DashboardButtonStyles\DashboardButtonStyleResource;
use App\Filament\Backoffice\Resources\DesignThemes\DesignThemeResource;
use App\Filament\Backoffice\Resources\Events\EventResource;
use App\Filament\Backoffice\Resources\FaqSections\FaqSectionResource;
use App\Filament\Backoffice\Resources\VenueCatalogLandings\VenueCatalogLandingResource;
use App\Filament\Backoffice\Resources\Geography\CityResource;
use App\Filament\Backoffice\Resources\Geography\DistrictResource;
use App\Filament\Backoffice\Resources\Geography\MetroLineResource;
use App\Filament\Backoffice\Resources\Geography\MetroStationResource;
use App\Filament\Backoffice\Resources\Leagues\LeagueResource;
use App\Filament\Backoffice\Resources\ManagerSpecializations\ManagerSpecializationResource;
use App\Filament\Backoffice\Resources\OperatorConversations\OperatorConversationResource;
use App\Filament\Backoffice\Resources\OperatorUsers\OperatorUserResource;
use App\Filament\Backoffice\Resources\Rewards\RewardResource;
use App\Filament\Backoffice\Resources\Promotions\PromotionResource;
use App\Filament\Backoffice\Resources\VenueRewardOverrides\VenueRewardOverrideResource;
use App\Filament\Backoffice\Resources\PageBuilder\BuilderComponentResource;
use App\Filament\Backoffice\Resources\PageBuilder\BuilderPageResource;
use App\Filament\Backoffice\Resources\PageBuilder\BuilderTemplateResource;
use App\Filament\Backoffice\Resources\Seasons\SeasonResource;
use App\Filament\Backoffice\Resources\ShopAttributes\ShopAttributeResource;
use App\Filament\Backoffice\Resources\ShopCategories\ShopCategoryResource;
use App\Filament\Backoffice\Resources\ShopProducts\ShopProductResource;
use App\Filament\Backoffice\Resources\ShopProductTypes\ShopProductTypeResource;
use App\Filament\Backoffice\Resources\ShopTags\ShopTagResource;
use App\Filament\Backoffice\Resources\SiteMedia\SiteMediaAssetResource;
use App\Filament\Backoffice\Resources\SiteMedia\SiteMediaFolderResource;
use App\Filament\Backoffice\Resources\SupportTickets\SupportTicketResource;
use App\Filament\Backoffice\Resources\SystemRoleProfiles\SystemRoleProfileResource;
use App\Filament\Backoffice\Resources\Teams\TeamResource;
use App\Filament\Backoffice\Resources\Tournaments\TournamentResource;
use App\Filament\Backoffice\Resources\Venues\AmenityResource;
use App\Filament\Backoffice\Resources\Venues\VenueDuplicateResource;
use App\Filament\Backoffice\Resources\Venues\VenueModerationResource;
use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Filament\Backoffice\Resources\VenueRentalBookings\VenueRentalBookingResource;
use App\Filament\Backoffice\Resources\RentalInvoiceTemplates\RentalInvoiceTemplateResource;
use App\Filament\Backoffice\Resources\VenueRentalInterests\VenueRentalInterestResource;
use App\Http\Middleware\RedirectGuestsToPortalLogin;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use App\Filament\Backoffice\Pages\BackofficeDashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

final class BackofficePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('backoffice')
            ->path('backoffice')
            ->brandName('MSKBA · Управление')
            ->authGuard('web')
            ->bootUsing(fn () => app()->setLocale('ru'))
            ->unsavedChangesAlerts()
            ->databaseTransactions()
            ->colors(['primary' => Color::Orange])
            ->navigationGroups([
                NavigationGroup::make('Контент')->collapsed(),
                NavigationGroup::make('Сайт и дизайн')->collapsed(),
                NavigationGroup::make('Пользователи и доступ')->collapsed(),
                NavigationGroup::make('Площадки')->collapsed(),
                NavigationGroup::make('География')->collapsed(),
                NavigationGroup::make('Аренда и документы')->collapsed(),
                NavigationGroup::make('Соревнования')->collapsed(),
                NavigationGroup::make('Магазин')->collapsed(),
                NavigationGroup::make('Лояльность')->collapsed(),
                NavigationGroup::make('Поддержка')->collapsed(),
                NavigationGroup::make('Система')->collapsed(),
            ])
            ->navigationItems([
                NavigationItem::make('Материалы и FAQ')
                    ->group('Контент')
                    ->icon('heroicon-o-document-text')
                    ->sort(1)
                    ->url(fn (): string => route('admin.content'))
                    ->isActiveWhen(fn (): bool => request()->routeIs('admin.content', 'admin.content.*'))
                    ->visible(fn (): bool => Gate::allows('manage-content')),

                NavigationItem::make('Интеграции площадок')
                    ->key('backoffice.venue-integrations')
                    ->group('Сайт и дизайн')
                    ->icon('heroicon-o-arrows-right-left')
                    ->sort(85)
                    ->url(fn (): string => VenueResource::getUrl())
                    ->visible(fn (): bool => VenueResource::canViewAny()
                        && ($user = auth('web')->user()) instanceof User
                        && app(BackofficePermissionManager::class)->allows(
                            $user, Permission::BACKOFFICE_VENUE_INTEGRATIONS_VIEW,
                        )),
                NavigationItem::make('OpenAPI 3.1 JSON')
                    ->key('backoffice.openapi')
                    ->group('Сайт и дизайн')
                    ->icon('heroicon-o-document-arrow-down')
                    ->sort(91)
                    ->url(fn (): string => route('backoffice.rest-api.openapi'), true)
                    ->visible(fn (): bool => RestApiExplorer::canAccess()),
                // Production does not enable Swagger / GraphiQL: these links
                // lead to the separately authenticated development environment.
                NavigationItem::make('REST API · Swagger (DEV)')
                    ->key('backoffice.dev.swagger')
                    ->group('Сайт и дизайн')
                    ->icon('heroicon-o-command-line')
                    ->sort(90)
                    ->url('https://dev.mskba.ru/backoffice/rest-api-explorer', true)
                    ->visible(fn (): bool => app()->environment('production')
                        && ($user = auth('web')->user()) instanceof User
                        && app(BackofficePermissionManager::class)->allows(
                            $user, Permission::BACKOFFICE_REST_API_EXPLORER_VIEW,
                        )),
                NavigationItem::make('OpenAPI 3.1 JSON (DEV)')
                    ->key('backoffice.dev.openapi')
                    ->group('Сайт и дизайн')
                    ->icon('heroicon-o-document-arrow-down')
                    ->sort(91)
                    ->url('https://dev.mskba.ru/backoffice/rest-api/openapi.json', true)
                    ->visible(fn (): bool => app()->environment('production')
                        && ($user = auth('web')->user()) instanceof User
                        && app(BackofficePermissionManager::class)->allows(
                            $user, Permission::BACKOFFICE_REST_API_EXPLORER_VIEW,
                        )),
                NavigationItem::make('GraphQL Explorer (DEV)')
                    ->key('backoffice.dev.graphql')
                    ->group('Сайт и дизайн')
                    ->icon('heroicon-o-code-bracket-square')
                    ->sort(92)
                    ->url('https://dev.mskba.ru/backoffice/graphql-explorer', true)
                    ->visible(fn (): bool => app()->environment('production')
                        && ($user = auth('web')->user()) instanceof User
                        && app(BackofficePermissionManager::class)->allows(
                            $user, Permission::BACKOFFICE_GRAPHQL_EXPLORER_VIEW,
                        )),
            ])
            ->resources([
                AccountDashboardWidgetResource::class,
                DashboardButtonStyleResource::class,
                DesignThemeResource::class,
                BuilderPageResource::class,
                BuilderTemplateResource::class,
                BuilderComponentResource::class,
                SiteMediaAssetResource::class,
                SiteMediaFolderResource::class,
                VenueResource::class,
                VenueRentalBookingResource::class,
                RentalInvoiceTemplateResource::class,
                VenueRentalInterestResource::class,
                EventResource::class,
                FaqSectionResource::class,
                VenueCatalogLandingResource::class,
                TeamResource::class,
                LeagueResource::class,
                SeasonResource::class,
                TournamentResource::class,
                ContractResource::class,
                ContractTemplateResource::class,
                AmenityResource::class,
                VenueModerationResource::class,
                CityResource::class,
                DistrictResource::class,
                MetroLineResource::class,
                MetroStationResource::class,
                VenueDuplicateResource::class,
                BackofficeUserResource::class,
                AccountInvitationResource::class,
                VenueOwnershipClaimResource::class,
                SystemRoleProfileResource::class,
                ManagerSpecializationResource::class,
                ShopCategoryResource::class,
                ShopProductResource::class,
                ShopProductTypeResource::class,
                ShopAttributeResource::class,
                ShopTagResource::class,
                SupportTicketResource::class,
                OperatorUserResource::class,
                OperatorConversationResource::class,
                RewardResource::class,
                PromotionResource::class,
                VenueRewardOverrideResource::class,
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.backoffice.code-editor-styles'))
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.backoffice.navigation-state'))
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => new HtmlString(
                '<link rel="stylesheet" href="'.e(asset('css/backoffice-dashboard.css').'?v=20261118-3').'">'
            ))
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => request()->routeIs('admin.content', 'admin.content.*')
                ? view('backoffice.content.head')
                : '')
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => new HtmlString(
                '<link rel="stylesheet" href="'.e(asset('css/backoffice-tables.css').'?v=20261102-1').'">'
            ))
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => new HtmlString(
                '<link rel="stylesheet" href="'.e(asset('css/backoffice-contracts.css').'?v=20261102-2').'">'
            ))
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => new HtmlString(
                '<link rel="stylesheet" href="'.e(asset('css/entity-banner-editor.css').'?v=20261031-2').'">'
            ))
            ->renderHook(PanelsRenderHook::BODY_END, fn (): HtmlString => new HtmlString(
                '<script src="'.e(asset('js/entity-banner-editor.js').'?v=20261031-2').'" defer></script>'
            ))
            // Filament's support chat uses the same browser-safe Reverb key
            // as the portal, never a private app secret or compiled .env.
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => new HtmlString(
                '<meta name="mskba-reverb-app-key" content="'.e((string) config('broadcasting.connections.reverb.key')).'">',
            ))
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.backoffice.venue-photo-crop-assets'))
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.backoffice.support-chat-assets'))
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => new HtmlString(<<<'HTML'
<script>
(function () {
    const prefix = 'Управление - ';
    const originalSuffix = ' - MSKBA · Управление';
    function updateTitle() {
        const title = document.querySelector('title');
        if (!title) return;
        const current = title.textContent.trim();
        if (current.startsWith(prefix)) return;
        const section = current.endsWith(originalSuffix)
            ? current.slice(0, -originalSuffix.length)
            : current;
        title.textContent = prefix + section + ' - MSKBA';
    }
    updateTitle();
    document.addEventListener('livewire:navigated', updateTitle);
})();
</script>
HTML))
            ->pages([BackofficeDashboard::class, Commissions::class, GraphQLExplorer::class, RestApiExplorer::class, PageBuilderAgentAccess::class, ProjectUpdatesAgentAccess::class, EditorialNewsAgentAccess::class, SocialIntegrationSettingsPage::class, UiStudioPage::class, EnvironmentStatePage::class])
            ->middleware([
                EncryptCookies::class, AddQueuedCookiesToResponse::class,
                StartSession::class, AuthenticateSession::class,
                ShareErrorsFromSession::class, VerifyCsrfToken::class,
                SubstituteBindings::class, DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([RedirectGuestsToPortalLogin::class, Authenticate::class], isPersistent: true);
    }
}
