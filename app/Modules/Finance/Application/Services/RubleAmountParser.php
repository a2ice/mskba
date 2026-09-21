<?php

namespace App\Modules\Finance\Application\Services;

use App\Modules\Finance\Domain\Exceptions\WalletException;

final class RubleAmountParser
{
    public function parse(string $value): int
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($value));

        if (! preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $normalized, $matches)) {
            throw new WalletException('Укажите корректную сумму в рублях, не более двух знаков после запятой.');
        }

        $rubles = (int) $matches[1];
        $kopeks = isset($matches[2])
            ? (int) str_pad($matches[2], 2, '0')
            : 0;

        if ($rubles > intdiv(PHP_INT_MAX - $kopeks, 100)) {
            throw new WalletException('Сумма перевода выходит за допустимый диапазон.');
        }

        $minor = ($rubles * 100) + $kopeks;
        if ($minor <= 0) {
            throw new WalletException('Сумма перевода должна быть больше нуля.');
        }

        return $minor;
    }
}
