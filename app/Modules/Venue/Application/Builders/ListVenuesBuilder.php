<?php

namespace App\Modules\Venue\Application\Builders;

use App\Modules\Venue\Domain\Models\Venue;
use Closure;
use Illuminate\Database\Eloquent\Builder;

final class ListVenuesBuilder
{
    public function build(?Closure $constraint = null): Builder
    {
        $query = $this->getQuery();

        if ($constraint) {
            $constraint($query);
        }

        return $query;
    }

    public function getQuery(): Builder
    {
        return Venue::query();
    }
}
