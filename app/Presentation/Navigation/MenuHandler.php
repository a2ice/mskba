<?php

namespace App\Presentation\Navigation;

interface MenuHandler
{
    /**
     * @return array<int, array{label: string, url: string, active: bool, visible: bool}>
     */
    public function items(): array;
}
