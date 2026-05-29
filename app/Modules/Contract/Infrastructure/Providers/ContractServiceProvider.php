<?php

namespace App\Modules\Contract\Infrastructure\Providers;

use App\Modules\Contract\Application\Contracts\ContractAccessInterface;
use App\Modules\Contract\Application\Services\EloquentContractAccessService;
use Illuminate\Support\ServiceProvider;

class ContractServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            ContractAccessInterface::class,
            EloquentContractAccessService::class,
        );
    }
}
