<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;

final class MinorAmountParser
{
    /** @var array<string, int> */
    private const EXPONENTS = [
        'BHD' => 3,
        'JOD' => 3,
        'JPY' => 0,
        'KWD' => 3,
    ];

    public function parse(string $amount, string $currency): int
    {
        $currency = strtoupper($currency);
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new VenueBookingTransitionException('В расчёте указана некорректная валюта.', 'INVALID_CONTRIBUTION_CURRENCY');
        }

        $normalized = str_replace(',', '.', trim($amount));
        $exponent = self::EXPONENTS[$currency] ?? 2;
        $pattern = $exponent === 0 ? '/^\d+$/' : '/^\d+(?:\.\d{1,'.$exponent.'})?$/';
        if (preg_match($pattern, $normalized) !== 1) {
            throw new VenueBookingTransitionException('Сумма содержит недопустимое число знаков после запятой.', 'INVALID_CONTRIBUTION_AMOUNT');
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $minorText = ltrim($whole.str_pad($fraction, $exponent, '0'), '0');
        $minorText = $minorText === '' ? '0' : $minorText;
        if (strlen($minorText) > 18 || (strlen($minorText) === 18 && strcmp($minorText, (string) PHP_INT_MAX) > 0)) {
            throw new VenueBookingTransitionException('Сумма слишком велика.', 'INVALID_CONTRIBUTION_AMOUNT');
        }

        return (int) $minorText;
    }

    public function exponent(string $currency): int
    {
        return self::EXPONENTS[strtoupper($currency)] ?? 2;
    }

    public function format(int $amountMinor, string $currency): string
    {
        $exponent = $this->exponent($currency);
        if ($exponent === 0) {
            return (string) $amountMinor;
        }

        $factor = 10 ** $exponent;
        $whole = intdiv($amountMinor, $factor);
        $fraction = rtrim(
            str_pad((string) ($amountMinor % $factor), $exponent, '0', STR_PAD_LEFT),
            '0',
        );

        return $fraction === '' ? (string) $whole : $whole.'.'.$fraction;
    }
}
