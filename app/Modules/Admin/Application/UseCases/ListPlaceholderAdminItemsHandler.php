<?php

namespace App\Modules\Admin\Application\UseCases;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class ListPlaceholderAdminItemsHandler
{
    /**
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    public function handle(string $section, array $filters): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 5), 50);

        return new LengthAwarePaginator(
            items: Collection::make(),
            total: 0,
            perPage: $perPage,
            currentPage: LengthAwarePaginator::resolveCurrentPage(),
            options: [
                'path' => request()->url(),
                'query' => request()->query(),
                'pageName' => 'page',
            ],
        );
    }
}
