<?php

namespace App\Providers\Filament;

use App\Filament\Backoffice\Pages\GraphQLExplorer;
use App\Filament\Backoffice\Pages\RestApiExplorer;
use App\Filament\Backoffice\Resources\AccountDashboardWidgets\AccountDashboardWidgetResource;
use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use App\Filament\Backoffice\Resources\ManagerSpecializations\ManagerSpecializationResource;
use App\Filament\Backoffice\Resources\SystemRoleProfiles\SystemRoleProfileResource;
use App\Filament\Backoffice\Resources\DashboardButtonStyles\DashboardButtonStyleResource;
use App\Filament\Backoffice\Resources\DesignThemes\DesignThemeResource;
use App\Filament\Backoffice\Resources\SiteMedia\SiteMediaAssetResource;
use App\Filament\Backoffice\Resources\SiteMedia\SiteMediaFolderResource;
use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Filament\Backoffice\Resources\Events\EventResource;
use App\Filament\Backoffice\Resources\Venues\AmenityResource;
use App\Filament\Backoffice\Resources\Venues\VenueModerationResource;
use App\Filament\Backoffice\Resources\Geography\CityResource;
use App\Filament\Backoffice\Resources\Geography\DistrictResource;
use App\Filament\Backoffice\Resources\Geography\MetroLineResource;
use App\Filament\Backoffice\Resources\Geography\MetroStationResource;
use App\Filament\Backoffice\Resources\Venues\VenueDuplicateResource;
use App\Filament\Backoffice\Resources\PageBuilder\BuilderPageResource;
use App\Filament\Backoffice\Resources\PageBuilder\BuilderTemplateResource;
use App\Filament\Backoffice\Resources\PageBuilder\BuilderComponentResource;
use App\Filament\Backoffice\Resources\SupportTickets\SupportTicketResource;
use App\Filament\Backoffice\Resources\OperatorUsers\OperatorUserResource;
use App\Filament\Backoffice\Resources\OperatorConversations\OperatorConversationResource;
use App\Http\Middleware\RedirectGuestsToPortalLogin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
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
                EventResource::class,
                AmenityResource::class,
                VenueModerationResource::class,
                CityResource::class,
                DistrictResource::class,
                MetroLineResource::class,
                MetroStationResource::class,
                VenueDuplicateResource::class,
                BackofficeUserResource::class,
                SystemRoleProfileResource::class,
                ManagerSpecializationResource::class,
                SupportTicketResource::class,
                OperatorUserResource::class,
                OperatorConversationResource::class,
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.backoffice.code-editor-styles'))
            // Filament's support chat uses the same browser-safe Reverb key
            // as the portal, never a private app secret or compiled .env.
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => new HtmlString(
                '<meta name="mskba-reverb-app-key" content="'.e((string) config('broadcasting.connections.reverb.key')).'">',
            ))
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
            ->pages([Dashboard::class, GraphQLExplorer::class, RestApiExplorer::class])
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
