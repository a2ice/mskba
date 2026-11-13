<?php

use App\Modules\Analytics\Application\Services\GameLiveViewSessionPruner;
use App\Modules\Coordination\Application\UseCases\CloseExpiredPollsHandler;
use App\Modules\Coordination\Application\UseCases\CloseExpiredVenueBookingAttendanceRoundsHandler;
use App\Modules\Identity\Application\Services\UserDuplicateDetector;
use App\Modules\Identity\Application\Services\UserFingerprintPruner;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Rewards\Application\Data\RewardMechanismContext;
use App\Modules\Rewards\Application\Services\RewardEngine;
use App\Modules\Rewards\Domain\Models\Reward;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Application\Services\PaymentReconciliationDispatcher;
use App\Modules\VenueBooking\Application\Services\VenueBookingExpiryDispatcher;
use App\Modules\VenueBooking\Application\Services\VenueBookingOperationalHealth;
use App\Modules\VenueBooking\Application\Services\VenueBookingOutboxDispatcher;
use App\Support\Privacy\TrackingDataHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
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

Artisan::command('privacy:prune-tracking {--batch=} {--max-batches=}', function (
    GameLiveViewSessionPruner $liveSessions,
    UserFingerprintPruner $fingerprints,
    TrackingDataHealth $health,
) {
    $batchSize = (int) ($this->option('batch') ?: config('identity_tracking.prune_batch_size', 1000));
    $maxBatches = (int) ($this->option('max-batches') ?: config('identity_tracking.prune_max_batches', 100));

    $sessionsDeleted = $liveSessions->prune($batchSize, $maxBatches);
    $fingerprintsDeleted = $fingerprints->prune($batchSize, $maxBatches);

    $this->info("Удалено live-сессий: {$sessionsDeleted}; fingerprint: {$fingerprintsDeleted}.");

    // Keep the scheduled path on indexed retention queries; full totals are manual.
    $expired = $health->expiredCounts();
    $context = [
        'live_sessions_deleted' => $sessionsDeleted,
        'fingerprints_deleted' => $fingerprintsDeleted,
        ...$expired,
    ];

    if ($expired['live_sessions_expired'] > 0 || $expired['fingerprints_expired'] > 0) {
        Log::warning('Tracking retention left expired rows after reaching the batch limit.', $context);
    } else {
        Log::info('Tracking retention completed.', $context);
    }
})->purpose('Пакетно удаляет tracking-данные старше настроенного retention');

Artisan::command('privacy:tracking-diagnose {--json}', function (TrackingDataHealth $health) {
    $snapshot = $health->snapshot();

    if ($this->option('json')) {
        $this->line(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return;
    }

    $this->table(['Metric', 'Value'], collect($snapshot)->map(fn ($value, $key) => [$key, $value])->values()->all());
})->purpose('Показывает объём и суточный рост tracking-данных без PII');


Artisan::command('rewards:grant-completed-rentals', function (RewardEngine $engine) {
    $reward = Reward::query()
        ->where('code', 'venue_rental_completed')
        ->where('is_enabled', true)
        ->with('currentVersion')
        ->first();

    $version = $reward?->currentVersion;
    if ($version === null || $version->mechanism_code !== 'venue_rental_completed') {
        $this->info('Начисление за аренду отключено.');

        return;
    }

    $count = 0;
    VenueBooking::query()
        ->where('status', 'confirmed')
        ->where('payment_state', 'confirmed')
        ->where('ends_at', '<=', now())
        ->where('confirmed_at', '>=', $version->valid_from)
        ->whereNotNull('requester_user_id')
        ->chunkById(100, function ($bookings) use ($engine, &$count): void {
            foreach ($bookings as $booking) {
                $grant = $engine->process(
                    'venue_rental_completed',
                    new RewardMechanismContext(
                        businessFactType: 'venue_rental_completed',
                        businessFactKey: 'booking:'.$booking->id,
                        payload: ['booking_id' => (int) $booking->id],
                    ),
                );

                if ($grant !== null) {
                    $count++;
                }
            }
        });

    $this->info('Обработано прав по завершённым арендам: '.$count);
})->purpose('Проверяет завершённые оплаченные аренды и идемпотентно начисляет фиксированные бонусы');

Schedule::command('coordination:close-expired')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('venue-booking:close-expired-attendance')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('identity:scan-user-duplicates')
    ->dailyAt('03:15')
    ->withoutOverlapping();

Schedule::command('privacy:prune-tracking')
    ->dailyAt('03:45')
    ->onOneServer()
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

Schedule::command('rewards:grant-completed-rentals')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();
