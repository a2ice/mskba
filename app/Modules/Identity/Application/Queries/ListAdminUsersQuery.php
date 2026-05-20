<?php

namespace App\Modules\Identity\Application\Queries;

use App\Modules\Identity\Application\Contracts\UserReadRepositoryContract;
use App\Modules\Identity\Application\DTO\UserQueryFiltersDTO;

class ListAdminUsersQuery
{
    // Этот класс может быть расширен параметрами фильтрации, пагинации и т.д.
    public function __construct(private UserReadRepositoryContract $userRepository) {}

    public function execute(array $filters = []): array
    {
        // Преобразуем массив фильтров в DTO
        $filtersDTO = new UserQueryFiltersDTO(...$filters);

        // Получаем всех пользователей
        return $this->userRepository->getAllUsers($filtersDTO);
    }
}