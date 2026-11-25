<?php

namespace App\Console\Commands;

use App\Modules\Venue\Application\Services\RentalSeoInventory;
use Illuminate\Console\Command;

final class RentalSeoInventoryCommand extends Command
{
    protected $signature = 'mskba:seo:rental-inventory';

    protected $description = 'Read-only aggregate inventory of public indoor courts and configured rental by geography';

    public function handle(RentalSeoInventory $inventory): int
    {
        $this->line(json_encode($inventory->snapshot(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
