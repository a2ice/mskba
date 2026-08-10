<?php

use App\Modules\Admin\Presentation\Http\Controllers\AdminContentController;
use App\Modules\Admin\Presentation\Http\Controllers\AdminController;
use App\Modules\Admin\Presentation\Http\Controllers\AdminEventsController;
use App\Modules\Admin\Presentation\Http\Controllers\AdminPageSeoController;
use App\Modules\Admin\Presentation\Http\Controllers\AdminSettingsController;
use App\Modules\Admin\Presentation\Http\Controllers\AdminTeamsController;
use App\Modules\Admin\Presentation\Http\Controllers\AdminTelegramChatsController;
use App\Modules\Admin\Presentation\Http\Controllers\AdminUsersController;
use App\Modules\Admin\Presentation\Http\Controllers\AdminVenueDuplicatesController;
use App\Modules\Admin\Presentation\Http\Controllers\AdminVenuesController;
use App\Modules\Audit\Presentation\Http\Controllers\AdminAuditController;
use App\Modules\Content\Presentation\Http\Controllers\NewsController;
use App\Modules\Coordination\Presentation\Http\Controllers\CoordinationController;
use App\Modules\Event\Presentation\Http\Controllers\EventController;
use App\Modules\Event\Presentation\Http\Controllers\EventGameController;
use App\Modules\Event\Presentation\Http\Controllers\GameController;
use App\Modules\Event\Presentation\Http\Controllers\NestedGameController;
use App\Modules\Identity\Presentation\Http\Controllers\AccountAvatarController;
use App\Modules\Identity\Presentation\Http\Controllers\AccountController;
use App\Modules\Identity\Presentation\Http\Controllers\AccountParticipationRolesController;
use App\Modules\Identity\Presentation\Http\Controllers\ActivateAccountAvatarController;
use App\Modules\Identity\Presentation\Http\Controllers\AuthController;
use App\Modules\Identity\Presentation\Http\Controllers\DeleteAccountAvatarController;
use App\Modules\Identity\Presentation\Http\Controllers\SearchPrivacyUsersController;
use App\Modules\Identity\Presentation\Http\Controllers\UpdateAccountPasswordController;
use App\Modules\Identity\Presentation\Http\Controllers\UpdateAccountPrivacySettingsController;
use App\Modules\Identity\Presentation\Http\Controllers\UpdatePlayerProfileController;
use App\Modules\Location\Presentation\Http\Controllers\AddressReverseGeocodeController;
use App\Modules\Location\Presentation\Http\Controllers\AddressSuggestController;
use App\Modules\Portal\Presentation\Http\Controllers\SiteSummaryController;
use App\Modules\Team\Presentation\Http\Controllers\AccountTeamsController;
use App\Modules\Team\Presentation\Http\Controllers\TeamController;
use App\Modules\Team\Presentation\Http\Controllers\TeamInvitationController;
use App\Modules\Team\Presentation\Http\Controllers\TeamPermissionController;
use App\Modules\Team\Presentation\Http\Controllers\TeamRoleController;
use App\Modules\Team\Presentation\Http\Controllers\TeamRosterController;
use App\Modules\Telegram\Presentation\Http\Controllers\StartTelegramBotLoginController;
use App\Modules\Telegram\Presentation\Http\Controllers\TelegramBotLoginStatusController;
use App\Modules\Telegram\Presentation\Http\Controllers\TelegramMiniAppController;
use App\Modules\Telegram\Presentation\Http\Controllers\TelegramWebLoginController;
use App\Modules\Tournament\Presentation\Http\Controllers\TournamentAdmissionController;
use App\Modules\Tournament\Presentation\Http\Controllers\TournamentCandidateSearchController;
use App\Modules\Tournament\Presentation\Http\Controllers\TournamentController;
use App\Modules\Tournament\Presentation\Http\Controllers\TournamentFormationController;
use App\Modules\Tournament\Presentation\Http\Controllers\TournamentMatchController;
use App\Modules\Tournament\Presentation\Http\Controllers\TournamentMatchSchedulingController;
use App\Modules\Tournament\Presentation\Http\Controllers\TournamentScheduleController;
use App\Modules\Tournament\Presentation\Http\Controllers\TournamentStaffCandidateSearchController;
use App\Modules\Venue\Presentation\Http\Controllers\VenueController;
use App\Modules\Venue\Presentation\Http\Controllers\VenuePhotoController;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Support\Facades\Route;

$themeResolver = app(ThemeResolver::class);

Route::get('/', function () use ($themeResolver) {
    return $themeResolver->page('welcome');
})->name('welcome');

Route::post('/site-summary/heartbeat', SiteSummaryController::class)
    ->middleware('throttle:30,1')
    ->name('site-summary.heartbeat');

Route::get('/login', function () use ($themeResolver) {
    return $themeResolver->page('auth.login');
})->name('login');

Route::get('/register', function () use ($themeResolver) {
    return $themeResolver->page('auth.register');
})->name('register');

Route::get('/privacy', function () use ($themeResolver) {
    return $themeResolver->page('legal.privacy');
})->name('privacy.policy')->defaults('breadcrumb', 'Персональные данные');

Route::prefix('faq')->group(function () use ($themeResolver) {
    Route::get('/', fn () => $themeResolver->page('faq.index'))
        ->name('faq.index')
        ->defaults('breadcrumb', 'FAQ');
    Route::get('/welcome', fn () => $themeResolver->page('faq.welcome'))
        ->name('faq.welcome')
        ->defaults('breadcrumb', 'Первые шаги');
});

Route::prefix('news')->group(function () {
    Route::get('/', [NewsController::class, 'index'])
        ->name('news.index')
        ->defaults('breadcrumb', 'Новости');
    Route::get('/{contentItem:alias}', [NewsController::class, 'show'])
        ->name('news.show');
});

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('guest', 'throttle:5,1')
        ->name('auth.login');

    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('guest', 'throttle:5,1')
        ->name('auth.register');

    Route::post('/telegram', TelegramWebLoginController::class)
        ->middleware('guest', 'throttle:10,1')
        ->name('auth.telegram');

    Route::post('/telegram/bot/start', StartTelegramBotLoginController::class)
        ->middleware('guest', 'throttle:10,1')
        ->name('auth.telegram.bot.start');

    Route::post('/telegram/bot/status', TelegramBotLoginStatusController::class)
        ->middleware('guest', 'throttle:60,1')
        ->name('auth.telegram.bot.status');

    Route::post('/restore', [AuthController::class, 'restore'])
        ->middleware('throttle:5,1')
        ->name('auth.restore');

    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware('auth')
        ->name('auth.logout');

    Route::get('/logout', [AuthController::class, 'logout'])
        ->middleware('auth')
        ->name('logout');

    Route::post('/confirm', [AuthController::class, 'confirm'])
        ->middleware('auth', 'throttle:5,1')
        ->name('auth.confirm');

    /* trashed routes starts here DONT TOUCH SO FAR */
    Route::post('/resolve-login', [AuthController::class, 'resolveLogin'])
        ->middleware('throttle:10,1')
        ->name('auth.resolve-login');

    Route::post('/verify', [AuthController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('auth.verify');
    /* trashed routes ends here */

});

Route::prefix('admin/content')
    ->middleware('auth', 'can:manage-content')
    ->group(function () {
        Route::get('/', [AdminContentController::class, 'index'])
            ->name('admin.content')
            ->defaults('breadcrumb', 'Контент');
        Route::get('/create', [AdminContentController::class, 'create'])
            ->name('admin.content.create')
            ->defaults('breadcrumb', 'Новый материал');
        Route::get('/seo', [AdminPageSeoController::class, 'index'])
            ->name('admin.content.seo')
            ->defaults('breadcrumb', 'SEO страниц');
        Route::get('/seo/{entityType}/{entityId}', [AdminPageSeoController::class, 'edit'])
            ->whereNumber('entityId')
            ->name('admin.content.seo.edit')
            ->defaults('breadcrumb', 'SEO страницы');
        Route::put('/seo/{entityType}/{entityId}', [AdminPageSeoController::class, 'update'])
            ->whereNumber('entityId')
            ->name('admin.content.seo.update');
        Route::post('/', [AdminContentController::class, 'store'])
            ->name('admin.content.store');
        Route::get('/{contentItem:alias}', [AdminContentController::class, 'edit'])
            ->name('admin.content.edit');
        Route::put('/{contentItem:alias}', [AdminContentController::class, 'update'])
            ->name('admin.content.update');
    });

Route::prefix('admin')
    ->middleware('auth', 'can:access-admin-panel')
    ->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('admin.dashboard')->defaults('breadcrumb', 'Панель управления');
        Route::get('/users', [AdminUsersController::class, 'index'])->name('admin.users')->defaults('breadcrumb', 'Пользователи');
        Route::post('/users/bulk-delete', [AdminUsersController::class, 'bulkDelete'])
            ->middleware('can:manage-users-as-superadmin')
            ->name('admin.users.bulk-delete');
        Route::post('/users/bulk-restore', [AdminUsersController::class, 'bulkRestore'])
            ->middleware('can:manage-users-as-superadmin')
            ->name('admin.users.bulk-restore');
        Route::post('/users/{user}/status', [AdminUsersController::class, 'updateStatus'])
            ->middleware('can:manage-users-as-superadmin')
            ->name('admin.users.status.update');
        Route::post('/users/{user}/operational-permissions', [AdminUsersController::class, 'updateOperationalPermissions'])
            ->middleware('can:manage-user-operational-permissions,user')
            ->name('admin.users.operational-permissions.update');
        Route::redirect('/venues/dublicates', '/admin/venues/duplicates')->name('admin.venues.dublicates');
        Route::get('/venues/duplicates', [AdminVenueDuplicatesController::class, 'index'])->name('admin.venues.duplicates')->defaults('breadcrumb', 'Дубли площадок');
        Route::post('/venues/duplicates/merge', [AdminVenueDuplicatesController::class, 'mergeBatch'])->name('admin.venues.duplicates.merge-batch');
        Route::post('/venues/duplicates/{venueDuplicate}/merge', [AdminVenueDuplicatesController::class, 'merge'])->name('admin.venues.duplicates.merge');
        Route::get('/venues', [AdminVenuesController::class, 'index'])->name('admin.venues')->defaults('breadcrumb', 'Площадки');
        Route::get('/venues/{venue}/edit', [AdminVenuesController::class, 'edit'])
            ->middleware('can:edit-venues-as-superadmin')
            ->name('admin.venues.edit')
            ->defaults('breadcrumb', 'Редактирование площадки');
        Route::put('/venues/{venue}', [AdminVenuesController::class, 'update'])
            ->middleware('can:edit-venues-as-superadmin')
            ->name('admin.venues.update');
        Route::get('/venues/{venue}/schedule', [AdminVenuesController::class, 'editSchedule'])
            ->middleware('can:edit-venues-as-superadmin')
            ->name('admin.venues.schedule.edit')
            ->defaults('breadcrumb', 'Расписание площадки');
        Route::put('/venues/{venue}/schedule', [AdminVenuesController::class, 'updateSchedule'])
            ->middleware('can:edit-venues-as-superadmin')
            ->name('admin.venues.schedule.update');
        Route::post('/venues/{venue}/photos', [AdminVenuesController::class, 'storePhoto'])
            ->middleware('can:edit-venues-as-superadmin', 'throttle:10,1')->name('admin.venues.photos.store');
        Route::patch('/venues/{venue}/photos/{photo}/active', [AdminVenuesController::class, 'activatePhoto'])
            ->middleware('can:edit-venues-as-superadmin', 'throttle:20,1')->whereNumber('photo')->name('admin.venues.photos.activate');
        Route::delete('/venues/{venue}/photos/{photo}', [AdminVenuesController::class, 'destroyPhoto'])
            ->middleware('can:edit-venues-as-superadmin', 'throttle:20,1')->whereNumber('photo')->name('admin.venues.photos.destroy');
        Route::post('/venues/bulk-delete', [AdminVenuesController::class, 'bulkDelete'])->name('admin.venues.bulk-delete');
        Route::post('/venues/bulk-restore', [AdminVenuesController::class, 'bulkRestore'])->name('admin.venues.bulk-restore');
        Route::post('/venues/bulk-block', [AdminVenuesController::class, 'bulkBlock'])->name('admin.venues.bulk-block');
        Route::post('/venues/bulk-unblock', [AdminVenuesController::class, 'bulkUnblock'])->name('admin.venues.bulk-unblock');
        Route::post('/venues/{venue}/status', [AdminVenuesController::class, 'updateStatus'])->name('admin.venues.status.update');
        Route::delete('/venues/{venue}', [AdminVenuesController::class, 'destroy'])->name('admin.venues.destroy');
        Route::post('/venues/{venueId}/restore', [AdminVenuesController::class, 'restore'])->whereNumber('venueId')->name('admin.venues.restore');
        Route::post('/venues/moderation/{moderationRequest}/approve', [AdminVenuesController::class, 'approve'])->name('admin.venues.moderation.approve');
        Route::post('/venues/moderation/{moderationRequest}/reject', [AdminVenuesController::class, 'reject'])->name('admin.venues.moderation.reject');
        Route::get('/events', [AdminEventsController::class, 'index'])->name('admin.events')->defaults('breadcrumb', 'Мероприятия');
        Route::get('/teams', [AdminTeamsController::class, 'index'])->name('admin.teams')->defaults('breadcrumb', 'Команды');
        Route::get('/audit', [AdminAuditController::class, 'index'])->name('admin.audit')->defaults('breadcrumb', 'Аудит');
        Route::get('/settings', [AdminSettingsController::class, 'index'])->name('admin.settings');
        Route::get('/telegram-chats', [AdminTelegramChatsController::class, 'index'])
            ->name('admin.telegram-chats')
            ->defaults('breadcrumb', 'Telegram-чаты');
        Route::post('/telegram-chats', [AdminTelegramChatsController::class, 'store'])
            ->name('admin.telegram-chats.store');
        Route::put('/telegram-chats/{telegramChat}', [AdminTelegramChatsController::class, 'update'])
            ->name('admin.telegram-chats.update');
    });

Route::prefix('venues')->group(function () {
    Route::get('/', [VenueController::class, 'index'])
        ->name('venues')
        ->defaults('breadcrumb', 'Площадки');
    Route::get('/search', [VenueController::class, 'search'])
        ->middleware('throttle:60,1')
        ->name('venues.search');
    Route::get('/proximity-check', [VenueController::class, 'proximityCheck'])
        ->middleware('throttle:30,1')
        ->name('venues.proximity-check');
    Route::middleware('auth')->group(function () {
        Route::get('/create', [VenueController::class, 'create'])
            ->name('venues.create')
            ->defaults('breadcrumb', 'Добавить площадку');
        Route::post('/', [VenueController::class, 'store'])
            ->name('venues.store');
    });
    Route::get('/{alias}/preview', [VenueController::class, 'preview'])
        ->middleware('throttle:60,1')
        ->name('venues.preview');
    Route::get('/{alias}', [VenueController::class, 'show'])->name('venues.show');
});

Route::prefix('events')->group(function () {
    Route::get('/', [EventController::class, 'index'])
        ->name('events.index')
        ->defaults('breadcrumb', 'Мероприятия');
    Route::get('/{event}/games/{game}', [EventGameController::class, 'show'])
        ->whereNumber('game')
        ->name('events.games.show')
        ->defaults('breadcrumb', 'Игра');

    Route::middleware('auth')->group(function () {
        Route::get('/create', [EventController::class, 'create'])
            ->name('events.create')
            ->defaults('breadcrumb', 'Новое мероприятие');
        Route::post('/', [EventController::class, 'store'])->name('events.store');
        Route::get('/{event}/edit', [EventController::class, 'edit'])
            ->name('events.edit')
            ->defaults('breadcrumb', 'Редактирование мероприятия');
        Route::put('/{event}', [EventController::class, 'update'])->name('events.update');
        Route::post('/{event}/participants', [EventController::class, 'join'])
            ->name('events.join');
        Route::delete('/{event}/participants/me', [EventController::class, 'leave'])
            ->name('events.leave');
        Route::patch('/{event}/participants/me', [EventController::class, 'participation'])
            ->name('events.participation');
        Route::get('/{event}/participants/candidates', [EventController::class, 'participantCandidates'])
            ->middleware('throttle:60,1')
            ->name('events.participants.candidates');
        Route::post('/{event}/participants/manage', [EventController::class, 'addParticipant'])
            ->name('events.participants.manage.store');
        Route::patch('/{event}/participants/{participant}/manage/status', [EventController::class, 'updateManagedParticipantStatus'])
            ->whereNumber('participant')
            ->name('events.participants.manage.status');
        Route::post('/{event}/participants/{participant}/responsibility', [EventController::class, 'requestResponsibility'])
            ->whereNumber('participant')
            ->name('events.participants.responsibility.request');
        Route::patch('/{event}/participants/{participant}/responsibility', [EventController::class, 'respondResponsibility'])
            ->whereNumber('participant')
            ->name('events.participants.responsibility.respond');
        Route::put('/{event}/participants/{participant}/responsibility/permissions', [EventController::class, 'updateResponsibilityPermissions'])
            ->whereNumber('participant')
            ->name('events.participants.responsibility.permissions.update');
        Route::delete('/{event}/participants/{participant}/responsibility', [EventController::class, 'removeResponsibility'])
            ->whereNumber('participant')
            ->name('events.participants.responsibility.destroy');
        Route::post('/{event}/cancel', [EventController::class, 'cancel'])->name('events.cancel');
        Route::put('/{event}/result', [EventController::class, 'complete'])->name('events.result.update');
        Route::post('/{event}/result/photos', [EventController::class, 'storeResultPhoto'])
            ->middleware('throttle:10,1')->name('events.result.photos.store');
        Route::put('/{event}/result/photos/{photo}', [EventController::class, 'updateResultPhoto'])
            ->middleware('throttle:30,1')->whereNumber('photo')->name('events.result.photos.update');
        Route::delete('/{event}/result/photos/{photo}', [EventController::class, 'destroyResultPhoto'])
            ->middleware('throttle:20,1')->whereNumber('photo')->name('events.result.photos.destroy');
        Route::post('/{event}/games', [GameController::class, 'createMiniGame'])->name('events.games.store');
        Route::get('/{event}/games/{game}/manage', [NestedGameController::class, 'manage'])->whereNumber('game')->name('events.games.manage');
        Route::put('/{event}/games/{game}', [NestedGameController::class, 'update'])->whereNumber('game')->name('events.games.update');
        Route::delete('/{event}/games/{game}', [NestedGameController::class, 'destroy'])->whereNumber('game')->name('events.games.destroy');
        Route::patch('/{event}/games/{game}/cancel', [NestedGameController::class, 'cancel'])->whereNumber('game')->name('events.games.cancel');
        Route::patch('/{event}/games/{game}/roster', [NestedGameController::class, 'roster'])->whereNumber('game')->name('events.games.roster');
        Route::patch('/{event}/games/{game}/score', [NestedGameController::class, 'score'])->whereNumber('game')->name('events.games.score');
        Route::patch('/{event}/games/{game}/statistics', [NestedGameController::class, 'statistics'])->whereNumber('game')->name('events.games.statistics');
        Route::patch('/{event}/games/{game}/statistics/complete', [NestedGameController::class, 'completeStatistics'])->whereNumber('game')->name('events.games.statistics.complete');
        Route::post('/{event}/games/{game}/statistics/confirm', [NestedGameController::class, 'confirmStatistics'])->whereNumber('game')->name('events.games.statistics.confirm');
        Route::get('/{event}/games/{game}/lifecycle', [NestedGameController::class, 'lifecycle'])->whereNumber('game')->name('events.games.lifecycle.show');
        Route::post('/{event}/games/{game}/start', [NestedGameController::class, 'start'])->whereNumber('game')->name('events.games.start');
        Route::post('/{event}/games/{game}/end', [NestedGameController::class, 'end'])->whereNumber('game')->name('events.games.end');
        Route::post('/{event}/games/{game}/periods/end', [NestedGameController::class, 'endPeriod'])->whereNumber('game')->name('events.games.periods.end');
        Route::post('/{event}/games/{game}/periods/start-next', [NestedGameController::class, 'startNextPeriod'])->whereNumber('game')->name('events.games.periods.start-next');
        Route::put('/{event}/games/{game}/lineup', [NestedGameController::class, 'lineup'])->whereNumber('game')->name('events.games.lineup.update');
    });

    Route::get('/{event}', [EventController::class, 'show'])
        ->name('events.show');
});

Route::prefix('tournaments')->group(function () {
    Route::get('/', [TournamentController::class, 'index'])->name('tournaments.index')->defaults('breadcrumb', 'Турниры');
    Route::middleware('auth')->group(function () {
        Route::get('/create', [TournamentController::class, 'create'])->name('tournaments.create')->defaults('breadcrumb', 'Новый турнир');
        Route::post('/', [TournamentController::class, 'store'])->name('tournaments.store');
        Route::get('/{tournament}/manage', [TournamentController::class, 'manage'])->name('tournaments.manage');
        Route::put('/{tournament}', [TournamentController::class, 'update'])->name('tournaments.update');
        Route::patch('/{tournament}/status', [TournamentController::class, 'status'])->name('tournaments.status');
        Route::post('/{tournament}/staff', [TournamentController::class, 'inviteStaff'])->name('tournaments.staff.invite');
        Route::get('/{tournament}/staff/candidates', TournamentStaffCandidateSearchController::class)->name('tournaments.staff.candidates');
        Route::post('/{tournament}/admissions/invite', [TournamentAdmissionController::class, 'invite'])->name('tournaments.admissions.invite');
        Route::get('/{tournament}/admissions/candidates', TournamentCandidateSearchController::class)->name('tournaments.admissions.candidates');
        Route::post('/{tournament}/admissions/apply', [TournamentAdmissionController::class, 'apply'])->name('tournaments.admissions.apply');
        Route::post('/{tournament}/admissions/{admission}/respond', [TournamentAdmissionController::class, 'respond'])->whereNumber('admission')->name('tournaments.admissions.respond');
        Route::delete('/{tournament}/admissions/{admission}', [TournamentAdmissionController::class, 'revoke'])->whereNumber('admission')->name('tournaments.admissions.revoke');
        Route::post('/{tournament}/formation/preview', [TournamentFormationController::class, 'preview'])->name('tournaments.formation.preview');
        Route::post('/{tournament}/formation/apply', [TournamentFormationController::class, 'apply'])->name('tournaments.formation.apply');
        Route::post('/{tournament}/schedule/preview', [TournamentScheduleController::class, 'preview'])->name('tournaments.schedule.preview');
        Route::post('/{tournament}/schedule/apply', [TournamentScheduleController::class, 'apply'])->name('tournaments.schedule.apply');
        Route::post('/{tournament}/matches', [TournamentMatchController::class, 'store'])->name('tournaments.matches.store');
        Route::patch('/{tournament}/matches/reorder', [TournamentMatchController::class, 'reorder'])->name('tournaments.matches.reorder');
        Route::delete('/{tournament}/matches/{match}', [TournamentMatchController::class, 'destroy'])->whereNumber('match')->name('tournaments.matches.destroy');
        Route::post('/{tournament}/matches/{match}/schedule', TournamentMatchSchedulingController::class)->whereNumber('match')->name('tournaments.matches.schedule');
        Route::put('/{tournament}/matches/{match}/schedule', [TournamentMatchSchedulingController::class, 'update'])->whereNumber('match')->name('tournaments.matches.reschedule');
        Route::patch('/{tournament}/staff/{membership}', [TournamentController::class, 'updateStaff'])->whereNumber('membership')->name('tournaments.staff.update');
        Route::post('/{tournament}/staff/{membership}/respond', [TournamentController::class, 'respondStaff'])->whereNumber('membership')->name('tournaments.staff.respond');
        Route::delete('/{tournament}/staff/{membership}', [TournamentController::class, 'revokeStaff'])->whereNumber('membership')->name('tournaments.staff.revoke');
        Route::delete('/{tournament}', [TournamentController::class, 'destroy'])->name('tournaments.destroy');
    });
    Route::get('/{tournament}', [TournamentController::class, 'show'])->name('tournaments.show');
});

Route::prefix('teams')->group(function () {
    Route::get('/', [TeamController::class, 'index'])->name('teams.index')->defaults('breadcrumb', 'Команды');
    Route::middleware('auth')->group(function () {
        Route::get('/create', [TeamController::class, 'create'])->middleware('can:team-create')->name('teams.create')->defaults('breadcrumb', 'Новая команда');
        Route::get('/name-suggestion', [TeamController::class, 'suggestName'])->middleware(['can:team-create', 'throttle:60,1'])->name('teams.name-suggestion');
        Route::post('/', [TeamController::class, 'store'])->middleware('can:team-create')->name('teams.store');
        Route::get('/{team}/edit', [TeamController::class, 'edit'])->name('teams.edit')->defaults('breadcrumb', 'Управление командой');
        Route::put('/{team}', [TeamController::class, 'update'])->name('teams.update');
        Route::delete('/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
        Route::post('/{team}/logo', [TeamController::class, 'storeLogo'])
            ->middleware('throttle:10,1')->name('teams.logo.store');
        Route::delete('/{team}/logo', [TeamController::class, 'destroyLogo'])
            ->middleware('throttle:20,1')->name('teams.logo.destroy');
        Route::delete('/{team}/members/{membership}', [TeamController::class, 'removeMember'])
            ->whereNumber('membership')->name('teams.members.destroy');
        Route::put('/{team}/roster', [TeamRosterController::class, 'update'])->name('teams.roster.update');
        Route::get('/{team}/invitation-candidates', [TeamInvitationController::class, 'search'])->name('teams.invitations.search');
        Route::post('/{team}/invitations', [TeamInvitationController::class, 'store'])->name('teams.invitations.store');
        Route::patch('/{team}/members/{membership}/captain', [TeamRoleController::class, 'captain'])->whereNumber('membership')->name('teams.members.captain');
        Route::put('/{team}/members/{membership}/permissions', [TeamPermissionController::class, 'update'])->whereNumber('membership')->name('teams.members.permissions');
        Route::patch('/team-invitations/{membership}', [TeamInvitationController::class, 'respond'])->whereNumber('membership')->name('teams.invitations.respond');
    });
    Route::get('/{team}', [TeamController::class, 'show'])->name('teams.show');
});

Route::prefix('coordination')->group(function () {
    Route::get('/', [CoordinationController::class, 'index'])
        ->name('coordination.index')
        ->defaults('breadcrumb', 'Опросы');

    Route::middleware('auth')->group(function () {
        Route::get('/create', [CoordinationController::class, 'create'])
            ->middleware('can:coordination-create')
            ->name('coordination.create')
            ->defaults('breadcrumb', 'Новый опрос');
        Route::post('/', [CoordinationController::class, 'store'])
            ->middleware('can:coordination-create')
            ->name('coordination.store');
        Route::post('/{coordination}/vote', [CoordinationController::class, 'vote'])
            ->name('coordination.vote');
        Route::post('/{coordination}/suggestion', [CoordinationController::class, 'suggest'])
            ->name('coordination.suggestion');
        Route::post('/{coordination}/close', [CoordinationController::class, 'close'])
            ->name('coordination.close');
        Route::post('/{coordination}/decision', [CoordinationController::class, 'decide'])
            ->name('coordination.decision');
        Route::post('/{coordination}/cancel', [CoordinationController::class, 'cancel'])
            ->name('coordination.cancel');
        Route::post('/{coordination}/event', [CoordinationController::class, 'createEvent'])
            ->name('coordination.event.store');
        Route::post('/{coordination}/event-change', [CoordinationController::class, 'applyEventChange'])
            ->name('coordination.event-change.apply');
    });

    Route::get('/{coordination}', [CoordinationController::class, 'show'])
        ->name('coordination.show')
        ->defaults('breadcrumb', 'Опрос');
});

Route::get('/integrations/address-suggest', AddressSuggestController::class)
    ->middleware(['throttle:30,1'])
    ->name('integrations.address-suggest');

Route::post('/integrations/address-reverse', AddressReverseGeocodeController::class)
    ->middleware(['throttle:20,1'])
    ->name('integrations.address-reverse');

Route::get('/integrations/main', [TelegramMiniAppController::class, 'main'])
    ->name('integrations.main');

Route::get('/telegram', [TelegramMiniAppController::class, 'home'])
    ->name('integrations.telegram.main');

Route::post('/integrations/telegram/auth', [TelegramMiniAppController::class, 'authenticate'])
    ->middleware(['throttle:20,1'])
    ->name('integrations.telegram.auth');

// Group routes for authenticated users
Route::middleware('auth')->group(function () use ($themeResolver) {

    // Dashboard route
    Route::get('/dashboard', fn () => $themeResolver->page('dashboard'))->name('dashboard');

    // Account routes
    Route::prefix('account')->group(function () {
        Route::get('/', [AccountController::class, 'index'])
            ->name('account')
            ->defaults('breadcrumb', 'Аккаунт');
        Route::post('/avatar', AccountAvatarController::class)
            ->middleware('throttle:10,1')
            ->name('account.avatar.store');
        Route::patch('/avatar/{avatar}/active', ActivateAccountAvatarController::class)
            ->middleware('throttle:20,1')
            ->whereNumber('avatar')
            ->name('account.avatar.activate');
        Route::delete('/avatar/{avatar}', DeleteAccountAvatarController::class)
            ->middleware('throttle:20,1')
            ->whereNumber('avatar')
            ->name('account.avatar.destroy');
        Route::get('/confirmation', [AccountController::class, 'confirmation'])
            ->name('account.confirmation')
            ->defaults('breadcrumb', 'Подтверждение аккаунта');
        Route::post('/confirmation', [AccountController::class, 'completeConfirmation'])
            ->name('account.confirmation.complete');
        Route::post('/confirmation/contact', [AccountController::class, 'storeConfirmationContact'])
            ->name('account.confirmation.contact.store');
        Route::post('/confirmation/contacts/{contact}/verification', [AccountController::class, 'startConfirmationContactVerification'])
            ->middleware('throttle:10,1')
            ->name('account.confirmation.contacts.verification.store');
        Route::post('/confirmation/contacts/{contact}/verification/confirm', [AccountController::class, 'confirmConfirmationContactVerification'])
            ->middleware('throttle:20,1')
            ->name('account.confirmation.contacts.verification.confirm');
        Route::get('/roles', [AccountParticipationRolesController::class, 'index'])
            ->name('account.roles')
            ->defaults('breadcrumb', 'Роли в проекте');
        Route::patch('/roles', [AccountParticipationRolesController::class, 'update'])
            ->name('account.roles.update');
        Route::get('/participation/{role}', [AccountController::class, 'participationRole'])
            ->name('account.participation-role');
        Route::patch('/participation/player/profile', UpdatePlayerProfileController::class)
            ->name('account.player-profile.update');
        Route::get('/settings', [AccountController::class, 'settings'])->name('account.settings');
        Route::put('/settings/password', UpdateAccountPasswordController::class)
            ->middleware('throttle:10,1')
            ->name('account.settings.password.update');
        Route::put('/settings/privacy', UpdateAccountPrivacySettingsController::class)
            ->middleware('throttle:20,1')
            ->name('account.settings.privacy.update');
        Route::get('/settings/privacy/users', SearchPrivacyUsersController::class)
            ->middleware('throttle:60,1')
            ->name('account.settings.privacy.users');
        Route::get('/notifications', [AccountController::class, 'notifications'])->name('account.notifications');
        Route::patch('/notifications/read-all', [AccountController::class, 'readAllNotifications'])
            ->name('account.notifications.read-all');
        Route::patch('/notifications/{notification}/read', [AccountController::class, 'readNotification'])
            ->name('account.notifications.read');
        Route::get('/contacts', [AccountController::class, 'contacts'])->name('account.contacts');
        Route::post('/contacts', [AccountController::class, 'storeContact'])->name('account.contacts.store');
        Route::patch('/contacts/{contact}/primary', [AccountController::class, 'setPrimaryContact'])
            ->name('account.contacts.primary');
        Route::delete('/contacts/{contact}', [AccountController::class, 'destroyContact'])
            ->name('account.contacts.destroy');
        Route::post('/contacts/{contact}/verification', [AccountController::class, 'startContactVerification'])
            ->middleware('throttle:10,1')
            ->name('account.contacts.verification.store');
        Route::post('/contacts/{contact}/verification/confirm', [AccountController::class, 'confirmContactVerification'])
            ->middleware('throttle:20,1')
            ->name('account.contacts.verification.confirm');
        Route::get('/contracts', [AccountController::class, 'contracts'])->name('account.contracts');
        Route::get('/contracts/{number}', [AccountController::class, 'contract'])->name('account.contracts.show');
        Route::get('/teams', AccountTeamsController::class)->name('account.teams')->defaults('breadcrumb', 'Мои команды');
        Route::get('/venues', [AccountController::class, 'venues'])
            ->name('account.venues')
            ->defaults('breadcrumb', 'Мои площадки');
        Route::get('/venues/{alias}', [AccountController::class, 'showVenue'])->name('account.venues.show');
        Route::get('/venues/{alias}/edit', [VenueController::class, 'edit'])->name('account.venues.edit');
        Route::put('/venues/{alias}', [VenueController::class, 'update'])->name('account.venues.update');
        Route::get('/venues/{alias}/status', [VenueController::class, 'status'])
            ->name('account.venues.status')
            ->defaults('breadcrumb', 'Модерация площадки');
        Route::get('/venues/{alias}/moderation-state', [VenueController::class, 'moderationState'])
            ->name('account.venues.moderation.state');
        Route::post('/venues/{alias}/moderation', [VenueController::class, 'submitModeration'])
            ->name('account.venues.moderation.submit');
        Route::post('/venues/{alias}/photos', [VenuePhotoController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('account.venues.photos.store');
        Route::patch('/venues/{alias}/photos/{photo}/active', [VenuePhotoController::class, 'activate'])
            ->middleware('throttle:20,1')
            ->whereNumber('photo')
            ->name('account.venues.photos.activate');
        Route::delete('/venues/{alias}/photos/{photo}', [VenuePhotoController::class, 'destroy'])
            ->middleware('throttle:20,1')
            ->whereNumber('photo')
            ->name('account.venues.photos.destroy');
        Route::get('/venues/{alias}/remove', [VenueController::class, 'remove'])->name('account.venues.remove');
        Route::get('/venues/{alias}/schedule', [AccountController::class, 'editVenueSchedule'])
            ->name('account.venues.schedule.edit');
        Route::put('/venues/{alias}/schedule', [AccountController::class, 'updateVenueSchedule'])
            ->name('account.venues.schedule.update');
    });
});
