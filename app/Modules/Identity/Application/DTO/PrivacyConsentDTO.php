<?php

namespace App\Modules\Identity\Application\DTO;

use Carbon\CarbonImmutable;

final readonly class PrivacyConsentDTO
{
    public function __construct(
        public string $documentVersion,
        public CarbonImmutable $acceptedAt,
        public string $source,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
