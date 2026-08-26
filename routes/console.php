<?php

use App\Modules\Coordination\Application\UseCases\CloseExpiredPollsHandler;
use App\Modules\Coordination\Application\UseCases\CloseExpiredVenueBookingAttendanceRoundsHandler;
use App\Modules\Identity\Application\Services\UserDuplicateDetector;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\VenueBooking\Application\Services\PaymentReconciliationDispatcher;
use App\Modules\VenueBooking\Application\Services\VenueBookingExpiryDispatcher;
use App\Modules\VenueBooking\Application\Services\VenueBookingOperationalHealth;
use App\Modules\VenueBooking\Application\Services\VenueBookingOutboxDispatcher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('coordination:close-expired', function (CloseExpiredPollsHandler $handler) {
    $this->info('Закрыто опросов: '.$handler->handle());
})->purpose('Закрывает опросы с истёкшим дедлайном');

Artisan::command('venue-booking:close-expired-attendance', function (CloseExpiredVenueBookingAttendanceRoundsHandler $handler) {
    $this->info('Закрыто сборов явки: '.$handler->handle());
})->purpose('Закрывает сборы явки с истёкшим дедлайном');

Artisan::command('identity:scan-user-duplicates', function (UserDuplicateDetector $detector) {
    $usersScanned = 0;
    $candidatesSeen = 0;

    User::query()
        ->whereNull('canonical_user_id')
        ->orderBy('id')
        ->chunkById(100, function ($users) use ($detector, &$usersScanned, &$candidatesSeen): void {
            foreach ($users as $user) {
                $usersScanned++;
                $candidatesSeen += $detector->scan($user)->count();
            }
        });

    $this->info("Проверено пользователей: {$usersScanned}; найдено/обновлено кандидатов: {$candidatesSeen}.");
})->purpose('Ищет потенциальные дубли пользователей без автоматического объединения');

Artisan::command('venue-booking:dispatch-outbox', function (VenueBookingOutboxDispatcher $dispatcher) {
    $this->info('Опубликовано событий аренды: '.$dispatcher->dispatchPending());
})->purpose('Повторно публикует ожидающие события аренды из transactional outbox');

Artisan::command('venue-booking:expire-due {--batch=100}', function (VenueBookingExpiryDispatcher $dispatcher) {
    $this->info('Поставлено задач на истечение: '.$dispatcher->dispatchDue((int) $this->option('batch')));
})->purpose('Ставит в очередь короткие идемпотентные задачи истечения hold');

Artisan::command('venue-booking:reconcile-payments {--batch=100}', function (PaymentReconciliationDispatcher $dispatcher) {
    $this->info('Поставлено задач сверки платежей: '.$dispatcher->dispatchStale((int) $this->option('batch')));
})->purpose('Сверяет зависшие payment intents с настроенным провайдером');

Artisan::command('venue-booking:diagnose {--json}', function (VenueBookingOperationalHealth $health) {
    $snapshot = $health->snapshot();
    if ($this->option('json')) {
        $this->line(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return;
    }
    $this->table(['Metric', 'Value'], collect($snapshot)->map(fn ($value, $key) => [$key, $value])->values()->all());
})->purpose('Показывает безопасные операционные метрики аренды без PII');

Schedule::command('coordination:close-expired')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('venue-booking:close-expired-attendance')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('identity:scan-user-duplicates')
    ->dailyAt('03:15')
    ->withoutOverlapping();

Schedule::command('venue-booking:dispatch-outbox')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('venue-booking:expire-due')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('venue-booking:reconcile-payments')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();
