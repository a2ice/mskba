<?php

namespace App\Modules\Identity\Application\Contracts;

interface ContactValueCheckerContract
{
    public function isContact(string $value): bool;
}
