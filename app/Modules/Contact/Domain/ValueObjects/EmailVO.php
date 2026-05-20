<?php

namespace App\Modules\Contact\Domain\ValueObjects;
use InvalidArgumentException;

class EmailVO
{
    public function __construct(private string $value) {
        $email = trim($this->value);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Некорректный формат email адреса.');
        }

        $this->value = $email;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

