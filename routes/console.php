<?php

use App\Modules\Coordination\Application\UseCases\CloseExpiredPollsHandler;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('coordination:close-expired', function (CloseExpiredPollsHandler $handler) {
    $this->info('Закрыто опросов: '.$handler->handle());
})->purpose('Закрывает опросы с истёкшим дедлайном');

Schedule::command('coordination:close-expired')
    ->everyMinute()
    ->withoutOverlapping();
