<?php

namespace App\GraphQL\Queries;

use App\Modules\Location\Application\Services\AddressDisplayFormatter;
use App\Modules\Venue\Application\UseCases\ListPublicVenuesHandler;
use App\GraphQL\Support\VenuePresenter;

final class Venue
{
    public function __construct(
        private readonly ListPublicVenuesHandler $venues,
        private readonly AddressDisplayFormatter $formatter,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args): ?array
    {
        $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return null;
        }

        $venue = $this->venues->find((int) $id);

        return $venue === null ? null : VenuePresenter::present($venue, $this->formatter, request());
    }
}
