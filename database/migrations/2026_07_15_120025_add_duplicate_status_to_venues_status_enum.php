<?php

use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LEGACY_DUPLICATE = 'duplicate';
    private const LEGACY_REMOVED = 'removed';

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(sprintf(
            "ALTER TABLE venues MODIFY status ENUM('%s') NOT NULL DEFAULT '%s'",
            implode("','", [
                VenueStatusEnum::UNCONFIRMED->value,
                VenueStatusEnum::CONFIRMED->value,
                self::LEGACY_DUPLICATE,
                VenueStatusEnum::BLOCKED->value,
                self::LEGACY_REMOVED,
            ]),
            VenueStatusEnum::UNCONFIRMED->value,
        ));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('venues')
            ->where('status', self::LEGACY_DUPLICATE)
            ->update([
                'status' => VenueStatusEnum::UNCONFIRMED->value,
                'canonical_venue_id' => null,
            ]);

        DB::statement(sprintf(
            "ALTER TABLE venues MODIFY status ENUM('%s') NOT NULL DEFAULT '%s'",
            implode("','", [
                VenueStatusEnum::UNCONFIRMED->value,
                VenueStatusEnum::CONFIRMED->value,
                VenueStatusEnum::BLOCKED->value,
                self::LEGACY_REMOVED,
            ]),
            VenueStatusEnum::UNCONFIRMED->value,
        ));
    }
};
