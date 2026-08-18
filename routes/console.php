<?php

use App\Modules\Coordination\Application\UseCases\CloseExpiredPollsHandler;
use App\Modules\Identity\Application\Services\UserDuplicateDetector;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('coordination:close-expired', function (CloseExpiredPollsHandler $handler) {
    $this->info('Закрыто опросов: '.$handler->handle());
})->purpose('Закрывает опросы с истёкшим дедлайном');

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

Schedule::command('coordination:close-expired')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('identity:scan-user-duplicates')
    ->dailyAt('03:15')
    ->withoutOverlapping();
