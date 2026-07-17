<?php

namespace App\Modules\Location\Application\Contracts;

use App\Modules\Location\Application\DTO\AddressSuggestionDTO;

interface AddressSuggestProvider
{
    /**
     * @return array<int, AddressSuggestionDTO>
     */
    public function suggest(string $query): array;

    public function reverse(float $latitude, float $longitude): ?AddressSuggestionDTO;
}
