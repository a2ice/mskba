<?php

namespace App\Modules\Ai\Domain\Exceptions;

use RuntimeException;

final class AiServiceException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 503,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(
            'ai_not_configured',
            'Сервис AI пока не подключён.',
            503,
        );
    }

    public static function connectionFailed(): self
    {
        return new self(
            'ai_connection_failed',
            'Не удалось подключиться к сервису AI. Попробуйте ещё раз позже.',
            503,
        );
    }

    public static function rateLimited(): self
    {
        return new self(
            'ai_rate_limited',
            'Сервис AI временно перегружен. Попробуйте ещё раз позже.',
            429,
        );
    }

    public static function requestRejected(): self
    {
        return new self(
            'ai_request_rejected',
            'Сервис AI отклонил запрос.',
            422,
        );
    }

    public static function generationFailed(): self
    {
        return new self(
            'generation_failed',
            'Не удалось сгенерировать модель игрока.',
            502,
        );
    }

    public static function timeout(): self
    {
        return new self(
            'generation_timeout',
            'Сервис AI не ответил вовремя. Попробуйте ещё раз.',
            504,
        );
    }
}
