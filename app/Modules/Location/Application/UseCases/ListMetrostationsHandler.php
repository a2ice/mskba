<?php

namespace App\Modules\Location\Application\UseCases;

use App\Modules\Location\Domain\Models\MetroStation;
use Illuminate\Support\Collection;

class ListMetrostationsHandler
{
    /**
     * @return array<int, string>
     */
    public function handle(): Collection
    {
        // sorted by name:
        return MetroStation::orderBy('name')->get();
    }
}