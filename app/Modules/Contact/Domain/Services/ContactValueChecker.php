<?php

namespace App\Modules\Contact\Domain\Services;

use App\Modules\Identity\Application\Contracts\ContactValueCheckerContract;

class ContactValueChecker implements ContactValueCheckerContract
{
    public function isContact(string $value): bool
    {
        $normalizedValue = mb_strtolower(trim($value));

        return $this->isEmail($normalizedValue) || $this->isPhone($normalizedValue);
    }

    private function isEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function isPhone(string $value): bool
    {
        return preg_match('/^\+?[0-9][0-9\-\s\(\)]{8,}$/', $value) === 1;
    }
}
