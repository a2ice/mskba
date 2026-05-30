<?php

namespace App\Presentation\Navigation;

interface MenuResolver
{
    /**
     * @return array<int, array{label: string, url: string, active: bool, visible: bool}>
     */
    public function resolve(string $page): array;
}
