<?php

namespace App\Modules\VenueIntegration\Application\Services;

use App\Modules\VenueIntegration\Domain\Models\VenueIntegrationSource;

/**
 * Same fixed-host identity requirement for all FindSport sources, regardless
 * of the playground ID. Never accepts an arbitrary URL from public callers.
 */
final class FindSportSourceIdentity
{
    public static function matches(VenueIntegrationSource $source): bool
    {
        if (! preg_match(
            '~^https://findsport\.ru/playground/([1-9][0-9]*)/?$~iD',
            (string) $source->endpoint_url,
            $match,
        )) {
            return false;
        }

        return $source->adapter_key === 'api'
            && (string) $source->external_id === $match[1];
    }
}
