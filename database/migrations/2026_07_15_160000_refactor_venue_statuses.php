<?php

use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->text('status_info')->nullable()->after('status');
            $table->softDeletes();
        });

        DB::table('venues')
            ->where('status', 'duplicate')
            ->update(['status' => VenueStatusEnum::UNCONFIRMED->value]);

        DB::table('venues')
            ->where('status', 'removed')
            ->update([
                'status' => VenueStatusEnum::UNCONFIRMED->value,
                'deleted_at' => now(),
            ]);

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                "ALTER TABLE venues MODIFY status ENUM('%s') NOT NULL DEFAULT '%s'",
                implode("','", array_column(VenueStatusEnum::cases(), 'value')),
                VenueStatusEnum::UNCONFIRMED->value,
            ));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                "ALTER TABLE venues MODIFY status ENUM('%s') NOT NULL DEFAULT '%s'",
                implode("','", [
                    VenueStatusEnum::UNCONFIRMED->value,
                    VenueStatusEnum::CONFIRMED->value,
                    'duplicate',
                    VenueStatusEnum::BLOCKED->value,
                    'removed',
                ]),
                VenueStatusEnum::UNCONFIRMED->value,
            ));
        }

        DB::table('venues')
            ->whereNotNull('deleted_at')
            ->update(['status' => 'removed']);

        Schema::table('venues', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn('status_info');
        });
    }
};
