<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Venue\Domain\Enums\VenueCourtEnvironmentEnum;
use App\Modules\Venue\Domain\Enums\VenueSurfaceTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Support\Text\CyrillicTransliterator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Configures the physical playing units together with a new venue.
 * A venue model's initial primary court is reused, not duplicated.
 */
final class VenueCourtConfigurator
{
    public function __construct(
        private readonly VenueCourtFacilitiesSynchronizer $facilities,
        private readonly CyrillicTransliterator $transliterator,
    ) {}

    public function configure(Venue $venue, array $input): void
    {
        $rows = array_values($input);
        if (count($rows) < 1 || count($rows) > 10) {
            throw ValidationException::withMessages([
                'initial_courts' => 'Добавьте хотя бы один зал или корт (не более десяти).',
            ]);
        }

        $usedAliases = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    'initial_courts' => 'Проверьте конфигурацию игровых зон.',
                ]);
            }
            $environment = VenueCourtEnvironmentEnum::tryFrom((string) ($row['environment'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($environment === null || $name === '' || mb_strlen($name) > 120) {
                throw ValidationException::withMessages([
                    'initial_courts' => 'У каждого зала или корта должны быть название и тип.',
                ]);
            }
            $hoopsCount = (int) ($row['hoops_count'] ?? 2);
            if (! in_array($hoopsCount, [1, 2], true)) {
                throw ValidationException::withMessages([
                    'initial_courts' => 'Количество колец должно быть 1 или 2.',
                ]);
            }
            $surface = (string) ($row['surface_type'] ?? '');
            if ($surface !== '' && VenueSurfaceTypeEnum::tryFrom($surface) === null) {
                throw ValidationException::withMessages([
                    'initial_courts' => 'Выбрано недопустимое покрытие.',
                ]);
            }

            $base = Str::slug($this->transliterator->transliterate($name));
            $base = $base ?: ($environment === VenueCourtEnvironmentEnum::OUTDOOR ? 'kort' : 'zal');
            $alias = $base;
            $suffix = 2;
            while (in_array($alias, $usedAliases, true)) {
                $alias = $base.'-'.$suffix++;
            }
            $usedAliases[] = $alias;
            $attributes = [
                'name' => $name,
                'alias' => $alias,
                'environment' => $environment->value,
                'sort_order' => ($index + 1) * 10,
                'is_primary' => $index === 0,
                'hoops_count' => $hoopsCount,
                'surface_type' => $surface !== '' ? $surface : null,
                'supports_halves' => $hoopsCount === 2,
                'allows_whole' => true,
                'allows_halves' => false,
            ];

            // Reconfigure the default primary, so exactly the requested
            // number of courts exists after a successful creation.
            $court = $index === 0
                ? $venue->primaryCourt()->firstOrFail()
                : new VenueCourt(['venue_id' => $venue->id]);
            $court->fill($attributes)->save();

            $this->facilities->sync($court, (array) ($row['amenity_ids'] ?? []));
        }
    }
}
