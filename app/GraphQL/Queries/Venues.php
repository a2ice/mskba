<?php

namespace App\GraphQL\Queries;

use App\Modules\Location\Application\Services\AddressDisplayFormatter;
use App\Modules\Venue\Application\UseCases\ListPublicVenuesHandler;
use App\Modules\Venue\Domain\Models\Venue;
use App\GraphQL\Support\VenuePresenter;
use GraphQL\Error\Error;
use Illuminate\Pagination\Cursor;

final class Venues
{
    public function __construct(
        private readonly ListPublicVenuesHandler $listVenues,
        private readonly AddressDisplayFormatter $formatter,
    ) {}

    /**
     * Return edges and Relay-style page info while reusing the REST public use case.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function __invoke(mixed $root, array $args): array
    {
        $first = $args['first'] ?? 30;
        if (! is_int($first) || $first < 1 || $first > 100) {
            throw new Error('first must be an integer between 1 and 100.');
        }

        $after = $args['after'] ?? null;
        $cursor = null;
        if ($after !== null) {
            if (! is_string($after) || strlen($after) > 512) {
                throw new Error('Invalid cursor.');
            }

            try {
                $cursor = Cursor::fromEncoded($after);
                $id = $cursor?->parameter('id');
                if ($cursor === null
                    || ! $cursor->pointsToNextItems()
                    || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    throw new \InvalidArgumentException('Invalid cursor.');
                }
            } catch (\Throwable) {
                throw new Error('Invalid cursor.');
            }
        }

        $page = $this->listVenues->handle($first, $cursor);
        $request = request();
        $edges = array_map(fn (Venue $venue): array => [
            'cursor' => $page->getCursorForItem($venue)->encode(),
            'node' => VenuePresenter::present($venue, $this->formatter, $request),
        ], $page->items());

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $page->hasMorePages(),
                'endCursor' => $page->nextCursor()?->encode(),
            ],
        ];
    }
}
