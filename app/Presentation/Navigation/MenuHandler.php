<?php

namespace App\Presentation\Navigation;

interface MenuHandler
{
    /**
     * @return array<int, array{label: string, url: string|null, active: bool, visible: bool, children?: array<int, array{label: string, url: string|null, active: bool, visible: bool}>}>
     */
    public function items(): array;
}
